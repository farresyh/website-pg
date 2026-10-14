<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Item 97: backup runs #26–#30 (2026-09-26..30) were killed by the 60s
 * worker timeout and stayed `running`, so /middleware/backups still shows
 * them as in progress. RunDatabaseBackupJob::failed() now closes a killed
 * run itself; this closes the ones left from before. Only rows started
 * over a day ago — no live run lasts that long — so a second run, or a
 * fresh database, changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('backup_runs')
            ->whereIn('status', ['queued', 'running'])
            ->where('created_at', '<', now()->subDay())
            ->update([
                'status' => 'failed',
                'error_message' => 'Run never finished: the job was killed by the 60s worker timeout (item 97, closed by migration 2026-10-14).',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // One-way: the original `running` was never a true state.
    }
};
