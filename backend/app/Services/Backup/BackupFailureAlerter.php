<?php

namespace App\Services\Backup;

use App\Models\AdminUser;
use App\Services\Membership\PlunkMailer;
use App\Services\Membership\PlunkSendException;
use Illuminate\Support\Facades\Log;

/**
 * ADR-039 decision 7: `Log::critical()` plus an email to every admin
 * user on any failed backup run — not gated on `SET-9`'s Telegram
 * sender, which isn't built yet. Shared by the raw
 * BackupHasFailed/CleanupHasFailed spatie events
 * (App\Listeners\Backup\LogAndAlertBackupFailure) and by
 * `App\Console\Commands\Backup\RunBackupCommand` for the restore-test-
 * failed case, which isn't a spatie event at all.
 *
 * Routed through `PlunkMailer` (ADR-027's transactional-email seam,
 * already proven live for membership OTP), not Laravel's native
 * `Mail::` facade — found 2026-09-14 that `MAIL_MAILER` is `log` in
 * production (never provisioned), so every prior `BackupFailedMail`
 * send since go-live (2026-09-02) silently wrote to the log instead of
 * reaching an inbox. The old `App\Mail\BackupFailedMail` Mailable +
 * its blade view were deleted with this change — this was their only
 * caller, confirmed via a repo-wide grep before removing them.
 */
class BackupFailureAlerter
{
    public function __construct(private readonly PlunkMailer $mailer) {}

    public function alert(string $context, string $message): void
    {
        Log::critical("[Backup] {$context}: {$message}");

        $recipients = AdminUser::query()->where('is_active', true)->pluck('email');

        foreach ($recipients as $email) {
            try {
                $this->mailer->sendView(
                    $email,
                    "[PekanGame Backup] {$context}",
                    'emails.backup-failure',
                    [
                        'context' => $context,
                        'messageText' => $message,
                    ]
                );
            } catch (PlunkSendException $e) {
                // One admin's bad/bouncing address shouldn't swallow the
                // alert for everyone else — log and keep going. This is
                // itself already inside the "something is wrong" path,
                // so there's no further alert to send about an alert
                // failing.
                Log::critical("[Backup] failed to send failure alert to {$email}: {$e->getMessage()}");
            }
        }
    }
}
