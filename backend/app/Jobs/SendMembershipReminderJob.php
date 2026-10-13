<?php

namespace App\Jobs;

use App\Models\MembershipReminder;
use App\Services\Membership\MembershipReminderService;
use App\Services\Membership\PlunkMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sends one renewal-reminder email (ADR-068 2026-10-13 addendum,
 * decisions 7-9). Re-checks the reminder is still due first: a member
 * who renewed, or a brand that stopped taking renewals, is `skipped`.
 *
 * Unlike `SendMembershipReceiptJob` (best-effort, swallowed), a failed
 * send is rethrown so the queue retries it, then recorded as `failed`
 * on the row the admin member page shows. Known ceiling: if Plunk took
 * the email but the response timed out, the retry sends a second copy.
 */
final class SendMembershipReminderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    /** Holds the unique lock if a queued job is lost; the command re-dispatches after this. */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $reminderId) {}

    public function uniqueId(): string
    {
        return (string) $this->reminderId;
    }

    public function handle(PlunkMailer $mailer, MembershipReminderService $reminders): void
    {
        $reminder = MembershipReminder::query()
            ->with(['membership.membershipPlan', 'membership.affiliate'])
            ->find($this->reminderId);

        if ($reminder === null || $reminder->status !== MembershipReminder::STATUS_QUEUED) {
            return;
        }

        if (! $reminders->isStillDue($reminder)) {
            $reminder->update([
                'status' => MembershipReminder::STATUS_SKIPPED,
                'error' => 'No longer due: renewed, outside its window, or the brand stopped taking renewals.',
            ]);

            return;
        }

        $membership = $reminder->membership;
        $tier = $membership->membershipPlan->name;
        $endsOn = $membership->expiresOn()->toFormattedDayDateString();
        $branding = $mailer->brandingFor($membership->affiliate);
        $before = $reminder->kind === MembershipReminder::KIND_BEFORE;

        $reminder->increment('attempts');

        try {
            $mailer->sendView(
                $membership->email,
                $before ? "Your {$tier} membership ends on {$endsOn}" : "Your {$tier} membership has ended",
                'emails.membership-reminder',
                [
                    'tier' => $tier,
                    'before' => $before,
                    'endsOn' => $endsOn,
                    'fee' => 'RM'.number_format($membership->membershipPlan->fee_sen / 100, 2),
                    'renewUrl' => $branding['storeUrl'].'/membership',
                    ...$branding,
                ]
            );
        } catch (Throwable $e) {
            $reminder->update(['error' => $e->getMessage()]);

            throw $e;
        }

        $reminder->update(['status' => MembershipReminder::STATUS_SENT, 'sent_at' => now(), 'error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        MembershipReminder::query()
            ->whereKey($this->reminderId)
            ->where('status', MembershipReminder::STATUS_QUEUED)
            ->update(['status' => MembershipReminder::STATUS_FAILED, 'error' => $e?->getMessage() ?? 'Send failed']);
    }
}
