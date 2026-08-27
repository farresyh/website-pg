<?php

namespace App\Observers;

use App\Events\BackupRunUpdated;
use App\Models\BackupRun;

/**
 * ADR-047 decision 1 — the single seam RunDatabaseBackupJob's
 * `$this->run->update([...])` calls (running, then success/failed)
 * broadcast through. Same discipline as OrderObserver.
 */
final class BackupRunObserver
{
    public function updated(BackupRun $run): void
    {
        if ($run->wasChanged('status')) {
            broadcast(new BackupRunUpdated($run));
        }
    }
}
