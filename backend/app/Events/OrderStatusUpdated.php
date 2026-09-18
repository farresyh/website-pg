<?php

namespace App\Events;

use App\Http\Controllers\TrackOrderController;
use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ADR-047 decisions 1/2/6 — replaces storefront's OrderStatusTracker poll
 * (the first, customer-facing item in decision 1's conversion order).
 * Broadcasts on a PUBLIC channel keyed by the order's own `order_number`
 * — no private-channel auth exists for guest checkout (ADR-011), the same
 * trust boundary `GET /api/track-order/{orderNumber}` already relies on
 * (`order_number` is `PG-` + 12 random base36 chars, OrderNumberService).
 * Payload is
 * `TrackOrderController::customerSafePayload()` verbatim — one shape for
 * the poll and the push (`TrackedOrderSchema` parses both). Carries the
 * buyer's own contact **masked** and the payment breakdown they saw at
 * checkout (ADR-065); never the internal financial/operational fields
 * `backend/AGENTS.md` reserves for admin-only responses (no raw contact,
 * no `standard_selling_price` / profit / `payment_ref`).
 *
 * Dispatched from `OrderObserver` via its own `DB::afterCommit()` +
 * try/catch, not by this class implementing `ShouldDispatchAfterCommit` —
 * see that observer's own doc comment for why (a real reproduced bug: a
 * Reverb-unreachable broadcast must never be allowed to throw out of the
 * `DB::transaction()` it's reporting on).
 *
 * ADR-047 addendum (2026-09-19) — also broadcasts on a second, PRIVATE
 * `admin-orders` channel (routes/channels.php), replacing the admin
 * Orders screen's own bounded poll (Resend Delivery) and fire-and-forget
 * gap (Retry Delivery) after a queued FulfillOrderJob/ResendOrderDeliveryJob
 * actually writes the new status. One admin-wide channel, not one per
 * order — same "the screen already refetches on any change" reasoning
 * BackupRunUpdated's own doc comment gives, not a per-run/per-order
 * channel. Reuses `broadcastWith()`'s existing customer-safe payload
 * verbatim as a pure "order {order_number} changed" signal — the admin
 * frontend refetches its own authoritative data from the REST API, it
 * never trusts this payload as the source of truth.
 */
final class OrderStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('order.'.$this->order->order_number),
            new PrivateChannel('admin-orders'),
        ];
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
        // One shape, one source (ADR-047 / ADR-065): identical to
        // `GET /api/track-order/{orderNumber}` — masked contact +
        // customer-facing payment breakdown, never an internal field.
        // `TrackedOrderSchema` on the storefront parses both paths.
        return TrackOrderController::customerSafePayload($this->order);
    }
}
