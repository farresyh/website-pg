<?php

namespace App\Services\Backup;

use App\Mail\BackupFailedMail;
use App\Models\AdminUser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * ADR-039 decision 7: `Log::critical()` plus an email to every admin
 * user on any failed backup run — not gated on `SET-9`'s Telegram
 * sender, which isn't built yet. Shared by the raw
 * BackupHasFailed/CleanupHasFailed spatie events
 * (App\Listeners\Backup\LogAndAlertBackupFailure) and by
 * `App\Console\Commands\Backup\RunBackupCommand` for the restore-test-
 * failed case, which isn't a spatie event at all.
 */
class BackupFailureAlerter
{
    public function alert(string $context, string $message): void
    {
        Log::critical("[Backup] {$context}: {$message}");

        $recipients = AdminUser::query()->where('is_active', true)->pluck('email');

        if ($recipients->isEmpty()) {
            return;
        }

        Mail::to($recipients->all())->send(new BackupFailedMail($context, $message));
    }
}
