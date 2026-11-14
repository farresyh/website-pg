<?php

namespace App\Console\Commands\Notification;

use App\Models\CustomerNotification;
use App\Services\Backup\BackupFailureAlerter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-116 2026-11-14 addendum, decision 10: no WhatsApp message fails
 * silently. Every minute, emails every admin when an outbox row has waited
 * more than 5 minutes past its due time (the gateway is down or the lane is
 * stuck), or when rows turned `unknown` or `failed`. One email per kind per
 * 30 minutes and none on recovery, like the queue-wait and supplier-balance
 * alerts. Session down and restriction are UptimeRobot's, on
 * `/api/health/whatsapp`.
 */
final class AlertWhatsAppOutboxCommand extends Command
{
    protected $signature = 'app:alert-whatsapp-outbox';

    protected $description = 'Email admins about WhatsApp messages that are stuck, failed or of unknown outcome';

    private const STUCK_AFTER_MINUTES = 5;

    /** Long enough that a row is seen by a run even if one minute is skipped. */
    private const RECENT_PROBLEM_MINUTES = 10;

    private const THROTTLE_SECONDS = 1800;

    public function handle(BackupFailureAlerter $alerter): int
    {
        $stuck = CustomerNotification::query()
            ->where('status', CustomerNotification::STATUS_QUEUED)
            ->where('due_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES));

        $stuckCount = (clone $stuck)->count();

        if ($stuckCount > 0 && Cache::add('whatsapp-outbox-alert:stuck', true, self::THROTTLE_SECONDS)) {
            $oldest = (clone $stuck)->min('due_at');
            $alerter->alert(
                'WhatsApp messages stuck',
                "{$stuckCount} WhatsApp message(s) are more than ".self::STUCK_AFTER_MINUTES." minutes late (oldest due {$oldest} UTC). "
                .'The gateway may be down: check /api/health/whatsapp and Middleware → WhatsApp.',
                'WhatsApp',
            );
        }

        $problems = CustomerNotification::query()
            ->whereIn('status', [CustomerNotification::STATUS_UNKNOWN, CustomerNotification::STATUS_FAILED])
            ->where('updated_at', '>=', now()->subMinutes(self::RECENT_PROBLEM_MINUTES))
            ->count();

        if ($problems > 0 && Cache::add('whatsapp-outbox-alert:problems', true, self::THROTTLE_SECONDS)) {
            $alerter->alert(
                'WhatsApp messages failed or uncertain',
                "{$problems} WhatsApp message(s) failed or may not have been delivered in the last ".self::RECENT_PROBLEM_MINUTES.' minutes. '
                .'Check Middleware → WhatsApp; an "unknown" one may have been sent, so look at the chat before sending it again by hand.',
                'WhatsApp',
            );
        }

        return self::SUCCESS;
    }
}
