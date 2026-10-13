<?php

namespace App\Console\Commands\Membership;

use App\Jobs\SendMembershipReminderJob;
use App\Models\MembershipReminder;
use App\Services\Membership\MembershipReminderService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * ADR-068 2026-10-13 addendum: records and queues each renewal reminder
 * that is due (`MembershipReminderService` decides which). The unique
 * (membership, kind, expires_at) row is the dedupe, so a second run the
 * same day queues nothing. A row still `queued` an hour later lost its
 * job (a queue flush) and is dispatched again; the job re-checks it is
 * still due.
 */
#[Signature('app:send-membership-reminders {--dry-run : List who would be emailed; record and send nothing}')]
#[Description('Queue the membership renewal-reminder emails that are due today (H-3 and H+1, Malaysia days).')]
class SendMembershipRemindersCommand extends Command
{
    public function handle(MembershipReminderService $reminders): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $queued = 0;

        foreach ($reminders->due() as ['membership' => $membership, 'kind' => $kind]) {
            if ($dryRun) {
                $this->line("{$kind}\t{$membership->email}\tbrand {$membership->affiliate_id}\tends {$membership->expiresOn()->toDateString()}");

                continue;
            }

            try {
                $reminder = MembershipReminder::query()->create([
                    'membership_id' => $membership->id,
                    'kind' => $kind,
                    'expires_at' => $membership->expires_at,
                    'status' => MembershipReminder::STATUS_QUEUED,
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            SendMembershipReminderJob::dispatch($reminder->id);
            $queued++;
        }

        if ($dryRun) {
            return self::SUCCESS;
        }

        $requeued = MembershipReminder::query()
            ->where('status', MembershipReminder::STATUS_QUEUED)
            ->where('updated_at', '<', now()->subHour())
            ->pluck('id')
            ->each(fn (int $id) => SendMembershipReminderJob::dispatch($id))
            ->count();

        $this->info("Queued {$queued} membership reminder(s); re-queued {$requeued} stale one(s).");
        Log::info('Membership reminders', ['queued' => $queued, 'requeued' => $requeued]);

        return self::SUCCESS;
    }
}
