<?php

namespace App\Listeners\Reseller;

use App\Events\OrderStatusUpdated;
use App\Models\ResellerBotOrderNotification;
use App\Services\OpenWa\OpenWaClient;
use App\Services\Order\DeliveryStatus;
use App\Services\Reseller\Bot\ResellerBotReplyFormatter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * ADR-076 decisions 4/5 — message 2 of the Bot channel's two-stage
 * order messaging. `OrderObserver` broadcasts `OrderStatusUpdated` on
 * *every* `payment_status`/`delivery_status` change (not gated to a
 * terminal state), and a single `OrderFulfillmentService::fulfill()`
 * call can write `delivery_status` more than once — this listener is
 * the one place that turns that noisy stream into at most one WhatsApp
 * message per newly-reached terminal state.
 *
 * A `ResellerBotOrderNotification` row existing for the order is what
 * scopes this to the Bot channel specifically (API/Portal-placed
 * wallet orders have no WhatsApp group and correctly never reach this
 * listener) — see the migration's own doc comment.
 *
 * Queued (`ShouldQueue`) on the same `orders` queue `OrderStatusUpdated`
 * itself broadcasts on (`$queue` below — a queued listener with no queue
 * set otherwise lands on `default`; see ADR-077 PR-3's note on the
 * missing `supervisor-default`) — small/fast, doesn't meaningfully delay
 * that queue, and keeps `OrderObserver`'s `DB::afterCommit()` closure
 * from ever blocking on an outbound OpenWA HTTP call.
 */
final class SendResellerBotOrderNotification implements ShouldQueue
{
    /** @var string Match OrderStatusUpdated::broadcastQueue() — the order lifecycle's own queue. */
    public $queue = 'orders';

    public function __construct(private readonly OpenWaClient $openWa) {}

    public function handle(OrderStatusUpdated $event): void
    {
        $order = $event->order;

        if (! in_array($order->delivery_status, [DeliveryStatus::Delivered, DeliveryStatus::Failed], true)) {
            return;
        }

        // Lock the notification row for the duration of the
        // check-then-update to guard against this listener processing
        // two `OrderStatusUpdated` fires for the same order concurrently
        // (defensive — not proven with a subprocess concurrency test,
        // since this sends a WhatsApp message, not money).
        DB::transaction(function () use ($order) {
            /** @var ResellerBotOrderNotification|null $notification */
            $notification = ResellerBotOrderNotification::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($notification === null) {
                return;
            }

            if ($notification->last_notified_delivery_status === $order->delivery_status->value) {
                return;
            }

            $this->openWa->sendText($notification->whatsapp_group_id, ResellerBotReplyFormatter::orderUpdate($order));

            $notification->update(['last_notified_delivery_status' => $order->delivery_status->value]);
        });
    }
}
