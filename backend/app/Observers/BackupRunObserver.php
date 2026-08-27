<?php

namespace App\Observers;

use App\Events\BackupRunUpdated;
use App\Models\BackupRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR-047 decision 1 — the single seam RunDatabaseBackupJob's
 * `$this->run->update([...])` calls (running, then success/failed)
 * broadcast through. Same discipline as OrderObserver, including the
 * DB::afterCommit()+try/catch shape — see that class's own doc comment
 * for the real bug (a Reverb-unreachable broadcast crashing the run
 * that's being reported on) this guards against.
 */
final class BackupRunObserver
{
    public function updated(BackupRun $run): void
    {
        if ($run->wasChanged('status')) {
            DB::afterCommit(function () use ($run) {
                try {
                    broadcast(new BackupRunUpdated($run));
                } catch (Throwable $e) {
                    Log::warning('Failed to broadcast BackupRunUpdated', [
                        'run_id' => $run->id,
                        'exception' => $e->getMessage(),
                    ]);
                }
            });
        }
    }
}
