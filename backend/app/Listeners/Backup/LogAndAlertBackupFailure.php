<?php

namespace App\Listeners\Backup;

use App\Services\Backup\BackupFailureAlerter;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\CleanupHasFailed;

/**
 * ADR-039 decision 7: reacts to spatie/laravel-backup's own raw domain
 * events (not its notification system — see config/backup.php's
 * `notifications.notifications`, deliberately left empty) so alerting
 * can reach every current `admin_users` row instead of one static
 * config address. Registered in AppServiceProvider::boot().
 */
class LogAndAlertBackupFailure
{
    public function __construct(private readonly BackupFailureAlerter $alerter)
    {
    }

    public function handleBackupHasFailed(BackupHasFailed $event): void
    {
        $this->alerter->alert('Backup run failed', $event->exception->getMessage());
    }

    public function handleCleanupHasFailed(CleanupHasFailed $event): void
    {
        $this->alerter->alert('Backup cleanup failed', $event->exception->getMessage());
    }
}
