<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateMembershipTier;
use App\Models\AffiliateSubscription;
use App\Models\AffiliateTierChange;
use Illuminate\Support\Facades\DB;

/**
 * ADR-058 58b: the admin-side lifecycle of an affiliate's wholesale-tier
 * subscription — assigning/changing the tier (RES-2 / RES-3) and
 * reactivating a lapsed one. The billing side (charging the monthly fee
 * from earnings, active → grace → lapsed) stays in
 * AffiliateTierFeeService (ADR-056); this service only manages which tier
 * an affiliate is on and writes the `affiliate_tier_changes` audit trail.
 */
final class AffiliateSubscriptionService
{
    /**
     * Assign a tier to an affiliate, or change the tier they're already on.
     * Writes one `affiliate_tier_changes` row per real change (never a
     * no-op audit row). A first assignment starts the subscription
     * `active` with a fresh 30-day cycle; a change to an existing
     * subscription swaps the tier only and leaves the billing cycle /
     * status untouched (a mid-cycle tier change doesn't re-bill, and
     * doesn't itself un-lapse a lapsed affiliate — that's `reactivate()`).
     */
    public function assignTier(
        Affiliate $affiliate,
        AffiliateMembershipTier $tier,
        ?int $adminUserId = null,
        ?string $note = null,
    ): AffiliateSubscription {
        return DB::transaction(function () use ($affiliate, $tier, $adminUserId, $note) {
            $subscription = AffiliateSubscription::query()
                ->where('affiliate_id', $affiliate->id)
                ->lockForUpdate()
                ->first();

            $fromTierId = $subscription?->affiliate_membership_tier_id;

            if ($fromTierId === $tier->id) {
                return $subscription;
            }

            if ($subscription === null) {
                $subscription = AffiliateSubscription::query()->create([
                    'affiliate_id' => $affiliate->id,
                    'affiliate_membership_tier_id' => $tier->id,
                    'status' => AffiliateSubscriptionStatus::Active,
                    'current_period_started_at' => now(),
                    'next_charge_at' => now()->addDays(AffiliateTierFeeService::CYCLE_DAYS),
                    'grace_until' => null,
                ]);
            } else {
                $subscription->update(['affiliate_membership_tier_id' => $tier->id]);
            }

            AffiliateTierChange::query()->create([
                'affiliate_id' => $affiliate->id,
                'admin_user_id' => $adminUserId,
                'from_tier_id' => $fromTierId,
                'to_tier_id' => $tier->id,
                'note' => $note,
            ]);

            return $subscription->refresh();
        });
    }

    /**
     * Bring a `grace` or `lapsed` subscription back to `active` with a
     * fresh 30-day cycle (ADR-056 decision 3/6 — reactivation is an
     * explicit admin action, never an automatic retry). Does not charge
     * the fee; the admin can trigger a charge separately if they want the
     * cycle collected immediately.
     */
    public function reactivate(AffiliateSubscription $subscription): AffiliateSubscription
    {
        $subscription->update([
            'status' => AffiliateSubscriptionStatus::Active,
            'current_period_started_at' => now(),
            'next_charge_at' => now()->addDays(AffiliateTierFeeService::CYCLE_DAYS),
            'grace_until' => null,
        ]);

        return $subscription->refresh();
    }
}
