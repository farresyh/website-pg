<?php

namespace App\Services\Membership;

use App\Models\Affiliate;
use App\Models\Membership;
use App\Models\MembershipReminder;
use App\Support\MalaysiaTime;

/**
 * Decides which renewal reminder a membership is due for (ADR-068
 * 2026-10-13 addendum, decisions 2-5). The daily command uses it to pick
 * who to remind and the send job uses it again just before sending, so
 * the two can never disagree.
 *
 * Days are Malaysia calendar days (`Membership::expiresOn()`), never UTC.
 * Windows, not exact days: a day the scheduler missed is caught the next
 * day. It reads `expires_at`, never `status`, so it does not depend on
 * `app:reset-membership-cycles` having run first.
 */
final class MembershipReminderService
{
    public const DAYS_BEFORE = 3;

    public const DAYS_AFTER = 1;

    public const LAST_AFTER_DAY = 7;

    /**
     * Memberships due a reminder that has not been recorded yet.
     *
     * @return list<array{membership: Membership, kind: string}>
     */
    public function due(): array
    {
        // A coarse UTC prefilter; kindDue() applies the exact Malaysia-day rule.
        $candidates = Membership::query()
            ->with(['membershipPlan', 'affiliate'])
            ->whereBetween('expires_at', [now()->subDays(self::LAST_AFTER_DAY + 1), now()->addDays(self::DAYS_BEFORE + 1)])
            ->get();

        $due = [];

        foreach ($candidates as $membership) {
            $kind = $this->kindDue($membership);

            $recorded = $kind !== null && $membership->reminders()
                ->where('kind', $kind)
                ->where('expires_at', $membership->expires_at)
                ->exists();

            if ($kind !== null && ! $recorded) {
                $due[] = ['membership' => $membership, 'kind' => $kind];
            }
        }

        return $due;
    }

    /** True while the reminder still matches the membership's current period and window. */
    public function isStillDue(MembershipReminder $reminder): bool
    {
        $membership = $reminder->membership;

        return $membership !== null
            && $membership->expires_at?->eq($reminder->expires_at) === true
            && $this->kindDue($membership) === $reminder->kind;
    }

    /** `before`, `after`, or null for the membership's current paid-through period, right now. */
    public function kindDue(Membership $membership): ?string
    {
        if ($membership->expires_at === null || $membership->membershipPlan === null || ! $this->takesRenewals($membership->affiliate)) {
            return null;
        }

        $today = now()->setTimezone(MalaysiaTime::TIMEZONE)->startOfDay();
        $endsOn = $membership->expiresOn();

        if ($membership->expires_at->isFuture()) {
            return $today->gte($endsOn->subDays(self::DAYS_BEFORE)) ? MembershipReminder::KIND_BEFORE : null;
        }

        return $today->betweenIncluded($endsOn->addDays(self::DAYS_AFTER), $endsOn->addDays(self::LAST_AFTER_DAY))
            ? MembershipReminder::KIND_AFTER
            : null;
    }

    /**
     * A suspended, deactivated or deleted brand's storefront answers 404
     * (`ResolveStorefrontBrand`), and a brand with membership off cannot
     * take a renewal — either way the email's link would be dead.
     * `Membership::affiliate()` does not load soft-deleted rows.
     */
    private function takesRenewals(?Affiliate $affiliate): bool
    {
        return $affiliate !== null
            && $affiliate->status === 'active'
            && $affiliate->membershipEnabledEffective();
    }
}
