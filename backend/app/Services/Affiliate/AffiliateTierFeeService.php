<?php

namespace App\Services\Affiliate;

use App\Models\AffiliateSubscription;
use App\Models\LedgerEntry;
use App\Services\Ledger\InsufficientBalanceException;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\DB;

/**
 * ADR-056 (grilled 2026-08-30) decisions 6 & 8: the single seam that
 * collects one billing cycle's affiliate wholesale-tier fee. The fee is
 * debited from the affiliate's EARNINGS ledger balance
 * (`owner_type='affiliate'`) — there is no prepaid deposit wallet in this
 * phase (decision 7). Manual-collection stopgap, same shape as
 * `MembershipFeeService`: no payment-gateway automation yet; a future
 * automated flow would call `chargeCycle()` too.
 *
 * State transitions (all guarded by a `lockForUpdate()` on the
 * subscription row, then the `ledger_accounts` mutex inside `debit()`):
 *
 *  - sufficient earnings → debit `affiliate_tier_fee`, advance
 *    `next_charge_at` to 30 days out, clear grace, status → `active`.
 *  - insufficient earnings → no ledger write; on the FIRST failure of a
 *    cycle, status → `grace` with `grace_until` ~3 days out. A retry
 *    while already in grace does NOT extend `grace_until`.
 *  - already in `grace` and `grace_until` is past → status → `lapsed`,
 *    no charge attempted. Pricing then falls back to
 *    `standard_selling_price` (`PricingService::calculateForAffiliate`).
 *  - `lapsed` → no-op here; reactivation is an admin action (ADR-058).
 */
final class AffiliateTierFeeService
{
    public const CYCLE_DAYS = 30;

    public const GRACE_DAYS = 3;

    public function __construct(private readonly LedgerService $ledger) {}

    public function chargeCycle(AffiliateSubscription $subscription): AffiliateSubscription
    {
        // The affiliate may have a subscription but no ledger_accounts row
        // yet (no orders credited earnings so far). Ensure it exists so
        // debit()'s lock target is present — the insufficient-balance path
        // is what we want for an empty account, not a missing-row error.
        $this->ledger->openAccount(LedgerOwnerType::Affiliate, $subscription->affiliate_id);

        return DB::transaction(function () use ($subscription) {
            /** @var AffiliateSubscription $subscription */
            $subscription = AffiliateSubscription::query()
                ->with(['tier' => fn ($query) => $query->withTrashed()])
                ->lockForUpdate()
                ->findOrFail($subscription->id);

            if ($subscription->status === AffiliateSubscriptionStatus::Lapsed) {
                return $subscription;
            }

            if ($subscription->status === AffiliateSubscriptionStatus::Grace
                && $subscription->grace_until !== null
                && $subscription->grace_until->isPast()) {
                $subscription->update(['status' => AffiliateSubscriptionStatus::Lapsed]);

                return $subscription;
            }

            // Item 33 (2026-09-26 audit): a second chargeCycle() call for
            // this same subscription — the scheduled command racing the
            // admin's manual "Charge Now" (Admin\AffiliateController::
            // chargeTierFee(), which deliberately has no due-date filter
            // of its own, so it can force an early first charge on a
            // freshly-assigned tier) — would otherwise block on the lock
            // above, then unblock into a row already charged this cycle
            // and charge it again. `next_charge_at` being in the future
            // alone isn't enough to detect that: assignTier() also sets
            // it 30 days out on a subscription that's never been charged
            // at all, which "Charge Now" must still be able to collect.
            // Only a *completed* charge for the current cycle sets both
            // `next_charge_at` to the future AND leaves an
            // `affiliate_tier_fee` ledger entry — checking both together
            // resets correctly once the cycle naturally elapses
            // (`next_charge_at` back in the past), unlike checking the
            // ledger entry alone (which would exist forever after the
            // subscription's very first successful charge).
            $alreadyChargedThisCycle = $subscription->status === AffiliateSubscriptionStatus::Active
                && $subscription->next_charge_at?->isFuture()
                && LedgerEntry::query()
                    ->where('owner_type', LedgerOwnerType::Affiliate->value)
                    ->where('owner_id', $subscription->affiliate_id)
                    ->where('type', 'affiliate_tier_fee')
                    ->where('reference_type', 'affiliate_subscription')
                    ->where('reference_id', $subscription->id)
                    ->exists();

            if ($alreadyChargedThisCycle) {
                return $subscription;
            }

            $feeSen = (int) $subscription->tier->monthly_fee_sen;

            try {
                $this->ledger->debit(
                    LedgerOwnerType::Affiliate,
                    $subscription->affiliate_id,
                    $feeSen,
                    'affiliate_tier_fee',
                    'affiliate_subscription',
                    $subscription->id,
                );
            } catch (InsufficientBalanceException) {
                if ($subscription->status !== AffiliateSubscriptionStatus::Grace) {
                    $subscription->update([
                        'status' => AffiliateSubscriptionStatus::Grace,
                        'grace_until' => now()->addDays(self::GRACE_DAYS),
                    ]);
                }

                return $subscription->refresh();
            }

            $subscription->update([
                'status' => AffiliateSubscriptionStatus::Active,
                'current_period_started_at' => now(),
                'next_charge_at' => now()->addDays(self::CYCLE_DAYS),
                'grace_until' => null,
            ]);

            return $subscription->refresh();
        });
    }
}
