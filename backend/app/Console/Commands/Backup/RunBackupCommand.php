<?php

namespace App\Console\Commands\Backup;

use App\Models\BackupRun;
use Illuminate\Console\Command;

/**
 * ADR-039 decision 2: the daily schedule entries (App\Console\TaskSchedule)
 * trigger this — it queues a run through `BackupRun::queue()`, the same
 * path the manual "Backup Now" admin action takes
 * (`Middleware\BackupController::store`), with `triggered_by`
 * distinguishing 'system' from an admin's name. `--source` picks the
 * database or the OpenWA gateway (2026-10-22 addendum).
 */
class RunBackupCommand extends Command
{
    protected $signature = 'app:run-backup {--source=database : database or openwa} {--triggered-by=system}';

    protected $description = 'Queue a backup run (ADR-039) — create, upload, restore-test, record as a BackupRun.';

    public function handle(): int
    {
        $source = (string) $this->option('source');

        if (! in_array($source, BackupRun::SOURCES, true)) {
            $this->error('--source must be one of: '.implode(', ', BackupRun::SOURCES));

            return self::FAILURE;
        }

        BackupRun::queue($source, (string) $this->option('triggered-by'));

        return self::SUCCESS;
    }
}
