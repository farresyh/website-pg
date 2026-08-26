<?php

namespace App\Console\Commands\Backup;

use App\Jobs\RunDatabaseBackupJob;
use App\Models\BackupRun;
use Illuminate\Console\Command;

/**
 * ADR-039 decision 2: the daily `Schedule::command()` entry
 * (routes/console.php) triggers this — creates a queued `BackupRun` row
 * and dispatches `RunDatabaseBackupJob`, the same job the manual
 * "Backup Now" admin action dispatches
 * (`Middleware\BackupController::store`), just with `triggered_by`
 * distinguishing 'system' from an admin's name.
 */
class RunBackupCommand extends Command
{
    protected $signature = 'app:run-backup {--triggered-by=system}';

    protected $description = 'Queue a full-database backup (ADR-039) — dump, locate archive, restore-test, record as a BackupRun.';

    public function handle(): int
    {
        $run = BackupRun::query()->create([
            'status' => 'queued',
            'triggered_by' => (string) $this->option('triggered-by'),
        ]);

        RunDatabaseBackupJob::dispatch($run);

        return self::SUCCESS;
    }
}
