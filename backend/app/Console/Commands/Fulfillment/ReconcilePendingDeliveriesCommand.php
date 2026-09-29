<?php

namespace App\Console\Commands\Fulfillment;

use App\Jobs\CheckSupplierDeliveryJob;
use App\Jobs\FulfillOrderJob;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderStatusService;
use App\Services\Order\PaymentStatus;
use App\Services\Supplier\Digiflazz\DigiflazzAdapter;
use App\Services\Supplier\SupplierAdapterFactory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-026 (ORD-10) — delivery-side counterpart to
 * ReconcilePendingPaymentsCommand (ADR-021/PAY-3). Runs on a schedule
 * (routes/console.php), same inert-until-real-cron pattern as every
 * other scheduled command here. Covers two distinct, previously-
 * undocumented ambiguous-failure shapes, both converging on
 * DeliveryStatus::NeedsReview — full story in ADR-026's Context:
 *
 * Gap X — an order stuck at delivery_status=processing with no
 * supplier_ref: every retry layer (TransientFailureRetryPolicy +
 * FulfillOrderJob's own job-level retries) was exhausted by a
 * connection-level failure, landing the job in failed_jobs with zero
 * supplier_response recorded. Resolved by re-dispatching
 * FulfillOrderJob (ADR-014: never call the supplier synchronously from
 * a scheduled command) — a genuine prior failure now succeeds cleanly;
 * a duplicate_reference response is handled by
 * OrderFulfillmentService::fulfill() itself (routes straight to
 * needs_review, never back through this command).
 *
 * Gap Y — delivery_status=failed rows already carrying
 * supplier_response.error_code=duplicate_reference from before this
 * feature existed (fulfill() used to treat it as a plain failure). A
 * one-time catch-up per row, not an ongoing concern: any *future*
 * duplicate_reference response is now caught directly inside fulfill()
 * itself and never reaches delivery_status=failed at all.
 *
 * Neither gap is resolved via SupplierAdapter::checkStatus() —
 * confirmed (ADR-026's Context) that Gamevion's check-status endpoint
 * needs its own invoice number, which is exactly the value missing in
 * both cases, and neither Gamevion's API nor its merchant dashboard
 * support a reference-number lookup. needs_review exists because no
 * automated resolution is possible with Gamevion's currently-confirmed
 * surface, not because this command doesn't try hard enough.
 *
 * ADR-032 decision 5 adds a third, structurally different job: the
 * poll backup for an async supplier's Pending state (checkStalePending()
 * below) — this one CAN resolve via SupplierAdapter::checkStatus(),
 * unlike Gap X/Y above, since an async supplier's own status-check
 * endpoint is keyed on our own reference_number, not a supplier-side
 * invoice number we may not have yet.
 */
#[Signature('app:reconcile-pending-deliveries')]
#[Description('Retry ambiguously-stuck deliveries once, and flag genuinely unresolvable ones for manual review.')]
class ReconcilePendingDeliveriesCommand extends Command
{
    public function handle(OrderStatusService $orderStatus, OrderFulfillmentService $fulfillment): int
    {
        $staleAfterMinutes = (int) config('services.delivery_reconciliation.stale_after_minutes');

        $this->retryStuckProcessing($staleAfterMinutes, $orderStatus, $fulfillment);
        $this->retryStuckNotStarted($staleAfterMinutes);
        $this->flagStaleDuplicateReferences($staleAfterMinutes, $orderStatus);
        $this->reclassifyConfirmedFailedNeedsReview($orderStatus);
        $this->checkStalePending($orderStatus);

        return self::SUCCESS;
    }

    /**
     * Gap X, reworked by the ADR-102 addendum (2026-09-29, review #3).
     * Since M-1 commits Processing just before the supplier call, a stale
     * Processing order with no supplier_ref means the worker died mid-call
     * (timeout SIGKILL, OOM) — the outcome is genuinely unknown. The old
     * re-dispatch could never succeed (startDelivery() rejects Processing),
     * so: a plain order on a supplier that replays a known reference goes
     * to Pending and is polled with that same reference; anything else
     * (another supplier, a combo mid-legs) goes to NeedsReview.
     */
    private function retryStuckProcessing(int $staleAfterMinutes, OrderStatusService $orderStatus, OrderFulfillmentService $fulfillment): void
    {
        $orders = Order::query()
            ->where('delivery_status', DeliveryStatus::Processing->value)
            ->whereNull('supplier_ref')
            ->where('updated_at', '<=', now()->subMinutes($staleAfterMinutes))
            ->with(['supplier', 'package'])
            ->get();

        $this->info("Recovering {$orders->count()} stuck-processing delivery(ies)...");

        foreach ($orders as $order) {
            $parked = DB::transaction(function () use ($order, $orderStatus) {
                $locked = Order::query()->lockForUpdate()->find($order->id);

                if ($locked === null || $locked->delivery_status !== DeliveryStatus::Processing) {
                    return false;
                }

                $replaySafe = ! $order->package?->is_combo
                    && SupplierAdapterFactory::resubmitReplaysOutcome((string) $order->supplier?->slug);

                $locked->update($replaySafe
                    ? [
                        'delivery_status' => $orderStatus->markPending($locked->delivery_status)->value,
                        'supplier_response' => ['error_message' => 'Worker stopped mid-delivery', 'auto_recovery' => OrderFulfillmentService::AUTO_RECOVERY_NOTE],
                    ]
                    : ['delivery_status' => $orderStatus->markNeedsReview($locked->delivery_status)->value]);

                Log::withContext(['order_number' => $locked->order_number]);
                Log::warning($replaySafe
                    ? 'Delivery reconciliation: stuck-processing order parked at Pending for a same-reference poll'
                    : 'Delivery reconciliation: stuck-processing order flagged for review');

                return $replaySafe;
            });

            if ($parked) {
                $fulfillment->scheduleRecoveryPoll($order->fresh());
            }
        }
    }

    /**
     * 2026-09-28 audit finding M-4: a paid order can be left stuck at
     * delivery_status=not_started with no automatic recovery and no
     * admin visibility — every FulfillOrderJob attempt exhausted its
     * tries (a genuine data problem, e.g. a missing
     * supplier_product_ref, throws OrderFulfillmentException from
     * fulfill()'s own guard checks, BEFORE phase 1 ever commits
     * Processing — never caught by fulfill()'s own NeedsReview-routing
     * catch, since that only wraps phase 2), or the job was queued but
     * never actually ran (a worker outage). Same shape as
     * retryStuckProcessing() above, at the earlier NotStarted state —
     * re-dispatching is safe (M-1's fix means phase 1 will regenerate
     * and durably persist a reference this time, and a repeat failure
     * now correctly routes to NeedsReview/Failed via M-1/M-3's fixes,
     * both already surfaced on the existing admin KPI cards, rather
     * than silently repeating the same stuck state).
     */
    private function retryStuckNotStarted(int $staleAfterMinutes): void
    {
        $orders = Order::query()
            ->where('payment_status', PaymentStatus::Paid->value)
            ->where('delivery_status', DeliveryStatus::NotStarted->value)
            ->where('is_test', false)
            ->where('updated_at', '<=', now()->subMinutes($staleAfterMinutes))
            ->get();

        $this->info("Retrying {$orders->count()} stuck-not-started delivery(ies)...");

        foreach ($orders as $order) {
            Log::withContext(['order_number' => $order->order_number]);
            Log::warning('Delivery reconciliation: re-dispatching a paid order stuck at not_started');

            FulfillOrderJob::dispatch($order);
        }
    }

    /**
     * Gap Y catch-up. Was widened by ADR-098 to also catch a stale
     * Failed order carrying one of Digiflazz's own "Terbentuk
     * Transaksi=Ya" rc codes — ADR-102 decision 4 REMOVES that
     * Digiflazz branch again: those codes are now a confirmed-Gagal
     * outcome that belongs on Failed permanently (Issue Voucher
     * already available there), so detouring one through NeedsReview
     * here would just be immediately reversed by
     * reclassifyConfirmedFailedNeedsReview() below in the very same
     * command run — a pointless flip-flop, not a correctness bug, but
     * confusing to read in the logs. Only Gamevion's own
     * `'duplicate_reference'` stays here: it is still genuinely
     * ambiguous (no status field confirms anything), so a stale Failed
     * row carrying it still needs the same Failed→NeedsReview catch-up
     * Gap Y always existed for.
     *
     * Row-locked despite this command only ever running as a single
     * scheduled instance — same discipline this codebase applies to
     * every other order-state mutation (backend/CLAUDE.md).
     */
    private function flagStaleDuplicateReferences(int $staleAfterMinutes, OrderStatusService $orderStatus): void
    {
        $orders = Order::query()
            ->where('delivery_status', DeliveryStatus::Failed->value)
            ->where('updated_at', '<=', now()->subMinutes($staleAfterMinutes))
            ->where('supplier_response->error_code', 'duplicate_reference')
            ->get();

        $this->info("Flagging {$orders->count()} stale unretriable delivery(ies) for review...");

        foreach ($orders as $order) {
            DB::transaction(function () use ($order, $orderStatus) {
                $locked = Order::query()->lockForUpdate()->find($order->id);

                if ($locked === null || $locked->delivery_status !== DeliveryStatus::Failed) {
                    return;
                }

                $needsReview = $orderStatus->markNeedsReview($locked->delivery_status);

                $locked->update(['delivery_status' => $needsReview->value]);

                Log::withContext(['order_number' => $locked->order_number]);
                Log::warning('Delivery reconciliation: flagged stale unretriable delivery for manual review');
            });
        }
    }

    /**
     * ADR-102 decision 7 — a PERMANENT safety net, not a one-time
     * cleanup: reclassifies any existing needs_review order whose
     * stored error_code is one of Digiflazz's 20 confirmed-Gagal
     * codes (`DigiflazzAdapter::TRANSACTION_ALREADY_FORMED_RC_CODES`,
     * the single source of truth) back to Failed. Decision 4's fix
     * already stops any FUTURE such order from ever reaching
     * needs_review in the first place — this clause exists for
     * anything already stuck there before the fix shipped, and as a
     * guard against a future misroute (a new bug, a webhook race)
     * landing one there again. Scoped to `supplier.slug = digiflazz`
     * for the exact same reason flagStaleDuplicateReferences() above
     * scopes its own rc-code branch — Gamevion's error_code is an
     * arbitrary, unbounded string that could coincidentally collide
     * with one of Digiflazz's numeric codes. No staleness window: this
     * is a live classification correction, not "wait and see if it
     * resolves itself" — a combo order's own order-level
     * supplier_response.error_code is never set (only its legs carry
     * failure_reason), so this query naturally never touches one.
     */
    private function reclassifyConfirmedFailedNeedsReview(OrderStatusService $orderStatus): void
    {
        $orders = Order::query()
            ->where('delivery_status', DeliveryStatus::NeedsReview->value)
            ->whereIn('supplier_response->error_code', DigiflazzAdapter::TRANSACTION_ALREADY_FORMED_RC_CODES)
            ->whereHas('supplier', fn ($q) => $q->where('slug', 'digiflazz'))
            ->get();

        $this->info("Reclassifying {$orders->count()} confirmed-Gagal delivery(ies) from needs_review to failed...");

        foreach ($orders as $order) {
            DB::transaction(function () use ($order, $orderStatus) {
                $locked = Order::query()->lockForUpdate()->find($order->id);

                if ($locked === null || $locked->delivery_status !== DeliveryStatus::NeedsReview) {
                    return;
                }

                $failed = $orderStatus->markNeedsReviewAsFailed($locked->delivery_status);

                $locked->update(['delivery_status' => $failed->value]);

                Log::withContext(['order_number' => $locked->order_number]);
                Log::warning('Delivery reconciliation: reclassified confirmed-Gagal delivery from needs_review to failed (ADR-102 decision 4)');
            });
        }
    }

    /**
     * ADR-032 decision 5/6 — the poll backup to a supplier webhook.
     * Per-order thresholds read from Supplier.api_config, falling back
     * to the config defaults, since only an async supplier (Digiflazz)
     * has real operational rules here (Gamevion never reaches Pending
     * at all). Never calls the supplier synchronously from this
     * scheduled command (ADR-014) — dispatches CheckSupplierDeliveryJob
     * instead, which does its own lockForUpdate() via
     * finalizePendingDelivery(), same as retryStuckProcessing() above.
     *
     * ADR-094 decision 7 (Phase 3b): a combo order has no `supplier`
     * of its own (decision 3) — its threshold config comes from its
     * components' shared supplier instead (decision 4's same-supplier-
     * only constraint means there's exactly one to read). The age-out
     * branch also cascades onto every still-Pending leg, not just the
     * order row, so `order_delivery_legs` stays accurate for the admin
     * leg breakdown rather than showing a stale "pending" leg under an
     * order already flagged for review.
     */
    private function checkStalePending(OrderStatusService $orderStatus): void
    {
        $defaultStaleMinutes = (int) config('services.delivery_reconciliation.pending_stale_minutes');
        $defaultMaxAgeDays = (int) config('services.delivery_reconciliation.max_reconcile_age_days');
        $defaultMaxHours = (int) config('services.delivery_reconciliation.pending_max_hours');

        $orders = Order::query()
            ->where('delivery_status', DeliveryStatus::Pending->value)
            ->with(['supplier', 'package.components.supplier'])
            ->get();

        foreach ($orders as $order) {
            $supplier = $order->package?->is_combo
                ? $order->package->components->first()?->supplier
                : $order->supplier;
            $apiConfig = $supplier?->api_config ?? [];
            $staleMinutes = $apiConfig['pending_stale_minutes'] ?? $defaultStaleMinutes;
            $maxAgeDays = $apiConfig['max_reconcile_age_days'] ?? $defaultMaxAgeDays;
            $maxHours = $apiConfig['pending_max_hours'] ?? $defaultMaxHours;

            // ADR-102 addendum (2026-09-29): also age out a Pending order
            // that hasn't moved in `pending_max_hours` — updated_at marks
            // entry into Pending, since a still-Pending/ambiguous poll never
            // writes to the order.
            if ($order->created_at->lte(now()->subDays($maxAgeDays)) || $order->updated_at->lte(now()->subHours($maxHours))) {
                DB::transaction(function () use ($order, $orderStatus) {
                    $locked = Order::query()->lockForUpdate()->find($order->id);

                    if ($locked === null || $locked->delivery_status !== DeliveryStatus::Pending) {
                        return;
                    }

                    $needsReview = $orderStatus->markNeedsReview($locked->delivery_status);

                    $locked->update(['delivery_status' => $needsReview->value]);

                    OrderDeliveryLeg::query()
                        ->where('order_id', $locked->id)
                        ->where('status', DeliveryStatus::Pending->value)
                        ->update(['status' => DeliveryStatus::NeedsReview->value]);

                    Log::withContext(['order_number' => $locked->order_number]);
                    Log::warning('Delivery reconciliation: Pending order too old to re-poll, or stuck past the pending window — flagged for review');
                });

                continue;
            }

            if ($order->updated_at->lte(now()->subMinutes($staleMinutes))) {
                Log::withContext(['order_number' => $order->order_number]);
                Log::info('Delivery reconciliation: dispatching stale-pending status check');

                CheckSupplierDeliveryJob::dispatch($order);
            }
        }
    }
}
