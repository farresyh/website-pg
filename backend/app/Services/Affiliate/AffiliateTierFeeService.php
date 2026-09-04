<?php

namespace App\Services\Affiliate;

use App\Models\AffiliateSubscription;
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
                ->with('tier')
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
