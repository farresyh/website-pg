<?php

namespace App\Observers;

use App\Events\OrderStatusUpdated;
use App\Models\Order;

/**
 * ADR-047 decision 1 — the single seam every payment_status/delivery_status
 * writer broadcasts through (OrderFulfillmentService's three transition
 * methods, XenditWebhookController/ChipWebhookController,
 * ReconcilePendingPaymentsCommand, CheckoutService's fully-voucher-covered
 * path, OrderResendService). A new call site that changes either column
 * gets broadcasting for free — nothing to remember to wire up there.
 */
final class OrderObserver
{
    public function updated(Order $order): void
    {
        if ($order->wasChanged(['payment_status', 'delivery_status'])) {
            broadcast(new OrderStatusUpdated($order));
        }
    }
}
