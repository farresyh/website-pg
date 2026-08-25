<?php

namespace App\Console\Commands\Fulfillment;

use App\Jobs\CheckSupplierDeliveryJob;
use App\Jobs\FulfillOrderJob;
use App\Models\Order;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderStatusService;
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
    public function handle(OrderStatusService $orderStatus): int
    {
        $staleAfterMinutes = (int) config('services.delivery_reconciliation.stale_after_minutes');

        $this->retryStuckProcessing($staleAfterMinutes);
        $this->flagStaleDuplicateReferences($staleAfterMinutes, $orderStatus);
        $this->checkStalePending($orderStatus);

        return self::SUCCESS;
    }

    /**
     * Gap X. No row-locking needed here — dispatching a job doesn't
     * mutate the row itself; FulfillOrderJob->fulfill() does its own
     * lockForUpdate() exactly as a fresh delivery attempt would.
     */
    private function retryStuckProcessing(int $staleAfterMinutes): void
    {
        $orders = Order::query()
            ->where('delivery_status', DeliveryStatus::Processing->value)
            ->whereNull('supplier_ref')
            ->where('updated_at', '<=', now()->subMinutes($staleAfterMinutes))
            ->get();

        $this->info("Retrying {$orders->count()} stuck-processing delivery(ies)...");

        foreach ($orders as $order) {
            Log::withContext(['order_number' => $order->order_number]);
            Log::info('Delivery reconciliation: re-dispatching stuck-processing order');

            FulfillOrderJob::dispatch($order);
        }
    }

    /**
     * Gap Y catch-up. Row-locked despite this command only ever running
     * as a single scheduled instance — same discipline this codebase
     * applies to every other order-state mutation (backend/CLAUDE.md).
     */
    private function flagStaleDuplicateReferences(int $staleAfterMinutes, OrderStatusService $orderStatus): void
    {
        $orders = Order::query()
            ->where('delivery_status', DeliveryStatus::Failed->value)
            ->where('supplier_response->error_code', 'duplicate_reference')
            ->where('updated_at', '<=', now()->subMinutes($staleAfterMinutes))
            ->get();

        $this->info("Flagging {$orders->count()} stale duplicate-reference delivery(ies) for review...");

        foreach ($orders as $order) {
            DB::transaction(function () use ($order, $orderStatus) {
                $locked = Order::query()->lockForUpdate()->find($order->id);

                if ($locked === null || $locked->delivery_status !== DeliveryStatus::Failed) {
                    return;
                }

                $needsReview = $orderStatus->markNeedsReview($locked->delivery_status);

                $locked->update(['delivery_status' => $needsReview->value]);

                Log::withContext(['order_number' => $locked->order_number]);
                Log::warning('Delivery reconciliation: flagged stale duplicate_reference for manual review');
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
     */
    private function checkStalePending(OrderStatusService $orderStatus): void
    {
        $defaultStaleMinutes = (int) config('services.delivery_reconciliation.pending_stale_minutes');
        $defaultMaxAgeDays = (int) config('services.delivery_reconciliation.max_reconcile_age_days');

        $orders = Order::query()
            ->where('delivery_status', DeliveryStatus::Pending->value)
            ->with('supplier')
            ->get();

        foreach ($orders as $order) {
            $apiConfig = $order->supplier?->api_config ?? [];
            $staleMinutes = $apiConfig['pending_stale_minutes'] ?? $defaultStaleMinutes;
            $maxAgeDays = $apiConfig['max_reconcile_age_days'] ?? $defaultMaxAgeDays;

            if ($order->created_at->lte(now()->subDays($maxAgeDays))) {
                DB::transaction(function () use ($order, $orderStatus) {
                    $locked = Order::query()->lockForUpdate()->find($order->id);

                    if ($locked === null || $locked->delivery_status !== DeliveryStatus::Pending) {
                        return;
                    }

                    $needsReview = $orderStatus->markNeedsReview($locked->delivery_status);

                    $locked->update(['delivery_status' => $needsReview->value]);

                    Log::withContext(['order_number' => $locked->order_number]);
                    Log::warning('Delivery reconciliation: Pending order too old to safely re-poll, flagged for review');
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
