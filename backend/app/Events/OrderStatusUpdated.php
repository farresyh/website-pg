<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ADR-047 decisions 1/2/6 — replaces storefront's OrderStatusTracker poll
 * (the first, customer-facing item in decision 1's conversion order).
 * Broadcasts on a PUBLIC channel keyed by the order's own `order_number`
 * — no private-channel auth exists for guest checkout (ADR-011), the same
 * trust boundary `GET /api/track-order/{orderNumber}` already relies on
 * (`order_number` is a `Str::ulid()`, OrderNumberService). Payload mirrors
 * `TrackOrderController`'s narrow, customer-safe shape exactly — never the
 * internal financial/operational fields `backend/AGENTS.md` reserves for
 * admin-only responses.
 *
 * `ShouldDispatchAfterCommit`: every current writer of
 * payment_status/delivery_status (OrderFulfillmentService's three
 * transition methods, both payment webhooks, the reconciliation command)
 * updates the Order inside `DB::transaction()`/`lockForUpdate()` —
 * broadcasting must wait for that transaction to actually commit, both so
 * a rolled-back change is never announced and so a future Redis-backed
 * queue (ADR-048) can't pick this job up before the commit is even
 * visible to it.
 */
final class OrderStatusUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}

    public function broadcastOn(): array
    {
        return [new Channel('order.'.$this->order->order_number)];
    }

    public function broadcastAs(): string
    {
        return 'order.status.updated';
    }

    /**
     * The one queue this broadcast shares — it's part of the same order
     * lifecycle FulfillOrderJob already runs on (ADR-020's
     * `queue-worker-orders`), and is small/fast enough not to meaningfully
     * delay it.
     */
    public function broadcastQueue(): string
    {
        return 'orders';
    }

    public function broadcastWith(): array
    {
        return [
            'order_number' => $this->order->order_number,
            'game' => $this->order->game !== null
                ? ['name' => $this->order->game->name, 'slug' => $this->order->game->slug]
                : null,
            'package_name' => $this->order->package?->name,
            'player_id' => $this->order->player_id,
            'server_id' => $this->order->server_id,
            'final_amount' => $this->order->final_amount,
            'payment_status' => $this->order->payment_status->value,
            'delivery_status' => $this->order->delivery_status->value,
            'created_at' => $this->order->created_at?->toISOString(),
        ];
    }
}
