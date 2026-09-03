<?php

namespace App\Jobs;

use App\Models\Membership;
use App\Services\Membership\PlunkMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR-068 decision 9 — the membership-fee receipt, sent on every
 * genuine create/transition through `MembershipFeeService::recordFeePaid()`
 * (admin-recorded and self-serve alike). Queued, never inline on the
 * CHIP webhook thread (backend/AGENTS.md).
 *
 * A receipt is best-effort: the membership is already active, and every
 * caller of the seam (an admin request, a webhook, the reconcile sweep)
 * must be immune to Plunk being down. So a send failure is logged and
 * swallowed here — no rethrow, no retry storm, no entry in
 * `failed_jobs`. (This also keeps the seam safe under the `sync` queue
 * driver the test suite uses.)
 *
 * `$transition` is `subscription` | `renewal` | `reactivation` — it
 * shapes the copy (and a zero `$amountSen` renders as a complimentary
 * membership, not a broken RM0.00 receipt — S12).
 */
final class SendMembershipReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $membershipId,
        public readonly string $transition,
        public readonly int $amountSen,
    ) {}

    public function handle(PlunkMailer $mailer): void
    {
        $membership = Membership::query()->with('membershipPlan')->find($this->membershipId);

        if ($membership === null || $membership->membershipPlan === null) {
            Log::warning('SendMembershipReceiptJob: membership or plan gone, skipping', [
                'membership_id' => $this->membershipId,
            ]);

            return;
        }

        $tier = $membership->membershipPlan->name;
        $expires = $membership->expires_at->toFormattedDayDateString();

        $amountLine = $this->amountSen > 0
            ? 'Amount paid: RM'.number_format($this->amountSen / 100, 2)
            : 'Complimentary membership — no charge.';

        $opening = match ($this->transition) {
            'renewal' => "Your {$tier} membership has been renewed.",
            'reactivation' => "Welcome back — your {$tier} membership is active again.",
            default => "Your {$tier} membership is now active.",
        };

        try {
            $mailer->send(
                $membership->email,
                "Your {$tier} membership — payment received",
                "{$opening}\n\n"
                ."{$amountLine}\n"
                ."Active through: {$expires}\n\n"
                ."Manage your membership any time at your account's Membership page.",
            );
        } catch (Throwable $e) {
            Log::warning('SendMembershipReceiptJob: receipt email not sent', [
                'membership_id' => $this->membershipId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
