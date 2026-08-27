<?php

namespace App\Observers;

use App\Events\OrderStatusUpdated;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR-047 decision 1 — the single seam every payment_status/delivery_status
 * writer broadcasts through (OrderFulfillmentService's three transition
 * methods, XenditWebhookController/ChipWebhookController,
 * ReconcilePendingPaymentsCommand, CheckoutService's fully-voucher-covered
 * path, OrderResendService). A new call site that changes either column
 * gets broadcasting for free — nothing to remember to wire up there.
 *
 * Deferred via DB::afterCommit() (not OrderStatusUpdated implementing
 * ShouldDispatchAfterCommit) specifically so the try/catch below is the
 * thing that actually runs at commit time — a real, reproduced bug: with
 * ShouldDispatchAfterCommit alone, a broadcast failure (Reverb unreachable)
 * throws from inside Laravel's own DatabaseTransactionRecord::executeCallbacks(),
 * which propagates straight out of the caller's DB::transaction() call —
 * meaning a real order's fulfillment, or a Price Sync/Backup run, would
 * appear to fail even though the DB write already committed successfully,
 * over something that is only ever a UI nicety. Broadcasting must never be
 * allowed to break the operation it's reporting on.
 */
final class OrderObserver
{
    public function updated(Order $order): void
    {
        if ($order->wasChanged(['payment_status', 'delivery_status'])) {
            DB::afterCommit(function () use ($order) {
                try {
                    broadcast(new OrderStatusUpdated($order));
                } catch (Throwable $e) {
                    Log::warning('Failed to broadcast OrderStatusUpdated', [
                        'order_id' => $order->id,
                        'exception' => $e->getMessage(),
                    ]);
                }
            });
        }
    }
}
