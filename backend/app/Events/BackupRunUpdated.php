<?php

namespace App\Events;

use App\Models\BackupRun;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
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
 */
final class BackupRunUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
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
     * RunDatabaseBackupJob's own queue (ADR-039/ADR-020 decision #5) —
     * this broadcast is a tiny follow-on to the same job's own status
     * writes, never worth a separate queue.
     */
    public function broadcastQueue(): string
    {
        return 'backups';
    }

    public function broadcastWith(): array
    {
        return ['run_id' => $this->run->id, 'status' => $this->run->status];
    }
}
