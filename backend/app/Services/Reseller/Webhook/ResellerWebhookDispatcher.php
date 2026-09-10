<?php

namespace App\Services\Reseller\Webhook;

use App\Jobs\Reseller\DeliverResellerWebhook;
use App\Models\Order;
use App\Models\ResellerWebhookDelivery;
use App\Support\ResellerOrderPayload;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ADR-084 PR-3 decision 4: the single entry point that turns "this order
 * reached a terminal state" into a queued webhook delivery — called from
 * `DispatchResellerOrderWebhook` (the `OrderStatusUpdated` listener, for
 * `order.delivered` / `order.failed`) and from
 * `Admin\OrderController::refundToWallet()` (for `order.refunded`, which
 * has no `delivery_status` change to listen on).
 *
 * Idempotent: the `(order_id, event)` unique index on
 * `reseller_webhook_deliveries` is the structural guarantee — a repeat
 * call for the same (order, event) is a no-op, never a second delivery.
 * Nothing here makes an external call; the actual POST is
 * `DeliverResellerWebhook`'s job.
 */
final class ResellerWebhookDispatcher
{
    public function dispatch(Order $order, ResellerWebhookEvent $event): void
    {
        if ($order->wallet_reseller_id === null) {
            return;
        }

        $order->loadMissing('walletReseller.webhook');
        $webhook = $order->walletReseller?->webhook;

        if ($webhook === null || ! $webhook->is_active) {
            return;
        }

        // A row already existing for this (order, event) means the
        // delivery was already queued — the terminal state was reached
        // before, or a concurrent listener fire beat us here.
        if (ResellerWebhookDelivery::query()
            ->where('order_id', $order->id)
            ->where('event', $event->value)
            ->exists()
        ) {
            return;
        }

        $payload = [
            ...ResellerOrderPayload::for($order),
            'event' => $event->value,
            'event_id' => (string) Str::uuid(),
            'occurred_at' => now()->toIso8601String(),
        ];

        try {
            $delivery = ResellerWebhookDelivery::query()->create([
                'reseller_id' => $order->wallet_reseller_id,
                'order_id' => $order->id,
                'event' => $event->value,
                'event_id' => $payload['event_id'],
                'payload' => $payload,
                'status' => ResellerWebhookDelivery::STATUS_PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost the race against a concurrent dispatch for the same
            // (order, event) — the winner queued the job.
            return;
        }

        Log::withContext(['order_number' => $order->order_number]);
        Log::info('Reseller delivery webhook queued', [
            'event' => $event->value,
            'event_id' => $payload['event_id'],
            'reseller_id' => $order->wallet_reseller_id,
        ]);

        DeliverResellerWebhook::dispatch($delivery->id);
    }
}
