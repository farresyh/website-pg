<?php

namespace App\Events;

use App\Models\PriceSyncRun;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ADR-047 decisions 1/3 — replaces `/middleware/price-sync`'s per-run
 * status poll (`getPriceSyncRun`, every 2s while `RUN_IN_FLIGHT`).
 * PRIVATE channel, scoped per run — this is admin-only operational data
 * (no customer-financial-field concern the way OrderStatusUpdated has),
 * authorized in routes/channels.php against the same `super_admin` role
 * `/middleware/price-sync`'s own REST routes already require.
 */
final class PriceSyncRunUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly PriceSyncRun $run) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('price-sync-run.'.$this->run->id)];
    }

    public function broadcastAs(): string
    {
        return 'price-sync-run.status.updated';
    }

    /**
     * SyncSupplierPricesJob's own queue (ADR-020 decision #5) — this
     * broadcast is a tiny follow-on to the same job's own status writes,
     * never worth a separate queue.
     */
    public function broadcastQueue(): string
    {
        return 'price-sync';
    }

    /**
     * Full run attributes — mirrors PriceSyncController::show()'s own
     * response shape exactly (no narrowing needed; only an authenticated
     * super_admin can ever subscribe to this channel).
     */
    public function broadcastWith(): array
    {
        return $this->run->toArray();
    }
}
