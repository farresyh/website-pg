<?php

namespace App\Events;

use App\Http\Controllers\TrackOrderController;
use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ADR-047 decisions 1/2/6 — replaces storefront's OrderStatusTracker poll
 * (the first, customer-facing item in decision 1's conversion order).
 * Broadcasts on a PUBLIC channel keyed by the order's own `order_number`
 * — no private-channel auth exists for guest checkout (ADR-011), the same
 * trust boundary `GET /api/track-order/{orderNumber}` already relies on
 * (`order_number` is a `Str::ulid()`, OrderNumberService). Payload is
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
 */
final class OrderStatusUpdated implements ShouldBroadcast
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
        // One shape, one source (ADR-047 / ADR-065): identical to
        // `GET /api/track-order/{orderNumber}` — masked contact +
        // customer-facing payment breakdown, never an internal field.
        // `TrackedOrderSchema` on the storefront parses both paths.
        return TrackOrderController::customerSafePayload($this->order);
    }
}
