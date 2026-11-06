<?php

namespace App\Console\Commands\Fulfillment;

use App\Jobs\CheckSupplierDeliveryJob;
use App\Jobs\FulfillOrderJob;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Supplier;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderStatusService;
use App\Services\Order\PaymentStatus;
use App\Services\Supplier\Digiflazz\DigiflazzAdapter;
use App\Services\Supplier\Manual\SupplierGroupNotifier;
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
 * Gap Y — a one-time Failed → NeedsReview catch-up for rows carrying the
 * old Gamevion API's `duplicate_reference` — was removed with that API
 * (ADR-137 decision 19): prod never held such a row.
 *
 * Gamevion's v2 API is looked up by our own reference (ADR-137), so it
 * resolves through checkStatus() like Digiflazz below.
 *
 * ADR-032 decision 5 adds a third, structurally different job: the
 * poll backup for an async supplier's Pending state (checkStalePending()
 * below) — this one CAN resolve via SupplierAdapter::checkStatus(),
 * unlike Gap X above, since an async supplier's own status-check
 * endpoint is keyed on our own reference_number, not a supplier-side
 * invoice number we may not have yet.
 */
#[Signature('app:reconcile-pending-deliveries')]
#[Description('Retry ambiguously-stuck deliveries once, and flag genuinely unresolvable ones for manual review.')]
class ReconcilePendingDeliveriesCommand extends Command
{
    public function handle(OrderStatusService $orderStatus, OrderFulfillmentService $fulfillment, SupplierGroupNotifier $notifier): int
    {
        $staleAfterMinutes = (int) config('services.delivery_reconciliation.stale_after_minutes');

        $this->retryStuckProcessing($staleAfterMinutes, $orderStatus, $fulfillment);
        $this->retryStuckNotStarted($staleAfterMinutes);
        $this->reclassifyConfirmedFailedNeedsReview($orderStatus);
        $this->checkStalePending($orderStatus);
        $this->remindStaleManualPending($notifier);

        return self::SUCCESS;
    }

    /**
     * ADR-125 decisions 15/59: a manual supplier's Pending order past its
     * reply SLA gets one reminder in the supplier's group per attempt (the
     * notifier's dedupe key). Real latency is the SLA plus up to one run.
     */
    private function remindStaleManualPending(SupplierGroupNotifier $notifier): void
    {
        Order::query()
            ->where('delivery_status', DeliveryStatus::Pending->value)
            ->where('is_test', false)
            ->whereHas('supplier', fn ($q) => $q->where('driver', Supplier::DRIVER_MANUAL))
            ->with('supplier')
            ->get()
            ->filter(fn (Order $order) => $order->isStalePending())
            ->each(fn (Order $order) => $notifier->slaReminder($order));
    }

    /**
     * Gap X, reworked by the ADR-102 addendum (2026-09-29, review #3).
     * Since M-1 commits Processing just before the supplier call, a stale
     * Processing order with no supplier_ref means the worker died mid-call
     * (timeout SIGKILL, OOM) — the outcome is genuinely unknown. The old
     * re-dispatch could never succeed (startDelivery() rejects Processing),
     * so: a plain order on a supplier that replays a known reference goes
     * to Pending and is polled with that same reference; any other plain
     * order goes to NeedsReview. A combo has one recovery of its own,
     * leg by leg, under the same rule (ADR-103 decision 13).
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
            if ($order->isCombo()) {
                $fulfillment->recoverDeadComboPass($order);

                continue;
            }

            $parked = DB::transaction(function () use ($order, $orderStatus) {
                $locked = Order::query()->lockForUpdate()->find($order->id);

                if ($locked === null || $locked->delivery_status !== DeliveryStatus::Processing) {
                    return false;
                }

                $replaySafe = SupplierAdapterFactory::resubmitReplaysOutcome($order->supplier);

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
     * because another supplier's error_code is its own string that could
     * coincidentally collide with one of Digiflazz's numeric codes. No staleness window: this
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
        // ADR-125 decision 3: a manual supplier's Pending order is never
        // polled or aged out (a person confirms it), so it is left out of
        // this scan. A combo has no supplier_id and is never manual.
        $orders = Order::query()
            ->where('delivery_status', DeliveryStatus::Pending->value)
            ->where(fn ($q) => $q->whereNull('supplier_id')->orWhereHas('supplier', fn ($s) => $s->apiBacked()))
            ->with(['supplier', 'package.components.supplier'])
            ->get();

        foreach ($orders as $order) {
            $policy = $order->fulfilmentSupplier()?->pendingPolicy() ?? Supplier::defaultPendingPolicy();

            if (! $policy['polls']) {
                continue;
            }

            $maxHours = $policy['max_hours'];

            // ADR-102 addendum (2026-09-29): also age out a Pending order
            // that hasn't moved in `pending_max_hours` — updated_at marks
            // entry into Pending, since a still-Pending/ambiguous poll never
            // writes to the order.
            if (! $order->sameReferenceWithinWindow() || $order->updated_at->lte(now()->subHours($maxHours))) {
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

            if ($order->isStalePending()) {
                Log::withContext(['order_number' => $order->order_number]);
                Log::info('Delivery reconciliation: dispatching stale-pending status check');

                CheckSupplierDeliveryJob::dispatch($order);
            }
        }
    }
}
