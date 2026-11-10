<?php

namespace App\Events;

use App\Models\BackupRun;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ADR-047 decisions 1/3 — replaces `/middleware/backups`'s list-level
 * poll (`refreshAll`, every 3s while any run on the current page is
 * queued/running). Unlike Price Sync's per-run channel, the frontend
 * already treats "a run changed" as "refetch stats + the current history
 * page" rather than tracking one run's fields incrementally — so this
 * stays a single admin-wide channel, not one per run, and the payload
 * only needs to say *that* something changed, not carry the full row.
 *
 * Dispatched from `BackupRunObserver` via its own `DB::afterCommit()` +
 * try/catch, not by this class implementing `ShouldDispatchAfterCommit`
 * — see `OrderObserver`'s doc comment for the real bug this avoids.
 */
final class BackupRunUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly BackupRun $run) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('backups')];
    }

    public function broadcastAs(): string
    {
        return 'backup-run.status.updated';
    }

    /**
     * The `default` lane, not this run's own queue: that lane has one worker,
     * busy with the RunDatabaseBackupJob that triggers this broadcast, so "running"
     * used to reach the screen only after the run had finished (2026-11-10).
     */
    public function broadcastQueue(): string
    {
        return 'default';
    }

    public function broadcastWith(): array
    {
        return ['run_id' => $this->run->id, 'status' => $this->run->status];
    }
}
