<?php

namespace App\Listeners\Broadcasting;

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

/**
 * ADR-047 2026-09-28 addendum: OrderObserver/PriceSyncRunObserver/
 * BackupRunObserver's own try/catch only guards the synchronous
 * `broadcast()` dispatch call — a failure *inside* the queued
 * `BroadcastEvent` job itself (e.g. Reverb briefly unreachable while the
 * job actually runs) previously exhausted its retries with zero log
 * line, on `price-sync`/`backups` specifically (`tries` was 1 there).
 * Registered in AppServiceProvider::boot(); this is visibility only —
 * Horizon's own failed-jobs UI already has the full payload, this just
 * makes the founder aware one happened instead of a customer/admin
 * screen silently going stale with nothing to grep for.
 *
 * 2026-11-10: any queue counts. The old list (orders, price-sync, backups)
 * had drifted: OrderStatusUpdated broadcasts on `default`, so the
 * customer's live order page could fail with no log line.
 */
class LogFailedBroadcastJob
{
    public function handle(JobFailed $event): void
    {
        if ($event->job->resolveName() !== BroadcastEvent::class) {
            return;
        }

        Log::error('Broadcast job exhausted retries — a live UI update was dropped', [
            'queue' => $event->job->getQueue(),
            'connection' => $event->connectionName,
            'exception' => $event->exception->getMessage(),
        ]);
    }
}
