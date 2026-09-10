<?php

namespace App\Listeners\Reseller;

use App\Events\OrderStatusUpdated;
use App\Services\Order\DeliveryStatus;
use App\Services\Reseller\Webhook\ResellerWebhookDispatcher;
use App\Services\Reseller\Webhook\ResellerWebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * ADR-084 PR-3 decision 4: turns a wallet order reaching `delivered` /
 * `failed` into a queued `order.delivered` / `order.failed` webhook.
 * `order.refunded` is NOT here — the admin wallet-refund action never
 * changes `delivery_status`, so it dispatches through
 * `ResellerWebhookDispatcher` directly (same as
 * `SendResellerBotOrderNotification`'s sibling refund path).
 *
 * Rides `OrderStatusUpdated` — the same ADR-047 "single seam"
 * (`OrderObserver` → `DB::afterCommit`) that already fires the Bot
 * channel's notification, so the "dispatched after the fulfillment
 * transaction commits, never inside it" guarantee (decision 4) comes for
 * free. `ResellerWebhookDispatcher`'s own `(order_id, event)` uniqueness
 * is what collapses `OrderObserver`'s noisy multi-write stream into at
 * most one delivery per (order, event).
 *
 * Queued on the `orders` queue `OrderStatusUpdated` itself broadcasts on
 * — the dispatcher does no external I/O (it only writes a row and queues
 * `DeliverResellerWebhook` on the isolated `reseller-webhooks` queue), so
 * it never meaningfully delays that queue.
 */
final class DispatchResellerOrderWebhook implements ShouldQueue
{
    /** @var string Match OrderStatusUpdated::broadcastQueue(). */
    public $queue = 'orders';

    public function __construct(private readonly ResellerWebhookDispatcher $dispatcher) {}

    public function handle(OrderStatusUpdated $event): void
    {
        $order = $event->order;

        if (! in_array($order->delivery_status, [DeliveryStatus::Delivered, DeliveryStatus::Failed], true)) {
            return;
        }

        $webhookEvent = ResellerWebhookEvent::forDeliveryStatus($order->delivery_status);

        if ($webhookEvent === null) {
            return;
        }

        $this->dispatcher->dispatch($order, $webhookEvent);
    }
}
