<?php

namespace App\Services\Pricing;

use App\Models\Membership;

/**
 * Decides which price a checkout Order is charged at, and returns one
 * PricingResolution the caller consumes wholesale — CheckoutService
 * (initiate + previewTotal) no longer branches on `standard`-vs-`member`
 * itself.
 *
 * Four outcomes, one seam (the 2x2 ADR-060's own prerequisites section
 * named):
 *
 *  - Member         a valid, in-quota membership was resolved. Member
 *                   price overrides everything else; `affiliateProfit`
 *                   is 0 and `normalSellingPrice` records what the
 *                   order would have cost without membership.
 *  - Affiliate      `$tierMarkupPct` is non-null — a third-party
 *                   affiliate storefront order priced against an active
 *                   wholesale tier (ADR-056 `calculateForAffiliate`).
 *  - Standard       no membership, no active tier — the plain guest
 *                   chain (`PricingService::calculate`).
 *  - Affiliate-lapsed  an affiliate whose tier lapsed: `$tierMarkupPct`
 *                   comes through null, so this resolves to the exact
 *                   same Standard path a guest order uses (ADR-060
 *                   2026-09-05 addendum decision 3) — not its own basis.
 *
 * This is an unlocked read that only *decides* the price. The locked
 * commits (membership quota decrement, voucher redemption) stay in
 * CheckoutService at the trust points VoucherService::redeem() already
 * uses.
 */
final class CheckoutPricingResolver
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly MembershipPricingService $membershipPricing,
    ) {}

    /**
     * @param  float|null  $tierMarkupPct  the affiliate's active wholesale
     *                                     tier markup, or null when there is no affiliate / the tier lapsed.
     *                                     Null until ADR-060 PR-2 wires `Host`-resolved affiliate pricing —
     *                                     with null it is behaviourally identical to the pre-resolver code.
     */
    public function resolve(
        int $costPriceSen,
        int $standardSellingPriceSen,
        float $packageMarkupPercent,
        float $affiliateMarkupPct,
        ?float $tierMarkupPct,
        ?int $membershipId,
    ): PricingResolution {
        $breakdown = $this->pricing->calculateForAffiliate(
            $costPriceSen,
            $standardSellingPriceSen,
            $tierMarkupPct,
            $affiliateMarkupPct,
        );

        $member = $this->resolveMember($membershipId, $costPriceSen, $packageMarkupPercent);

        if ($member !== null) {
            return new PricingResolution(
                costPriceSen: $costPriceSen,
                standardSellingPriceSen: $standardSellingPriceSen,
                sellingPriceSen: $member['memberPriceSen'],
                platformProfitSen: $member['memberPriceSen'] - $costPriceSen,
                affiliateProfitSen: 0,
                basis: PricingBasis::Member,
                normalSellingPriceSen: $breakdown->sellingPrice,
                membershipId: $member['membershipId'],
                memberDiscountPercent: $member['discountPercent'],
            );
        }

        return new PricingResolution(
            costPriceSen: $breakdown->costPrice,
            standardSellingPriceSen: $breakdown->standardSellingPrice,
            sellingPriceSen: $breakdown->sellingPrice,
            platformProfitSen: $breakdown->platformProfit,
            affiliateProfitSen: $breakdown->affiliateProfit,
            basis: $tierMarkupPct !== null ? PricingBasis::Affiliate : PricingBasis::Standard,
        );
    }

    /**
     * Ported verbatim from CheckoutService::resolveMemberPricingFor().
     * Returns null (standard pricing applies) for three reasons treated
     * identically — no membership, plan missing (defensive only), or
     * quota insufficient for this order (the confirmed 2026-08-29
     * fallback; checkout is never blocked over it).
     *
     * @return array{membershipId: int, memberPriceSen: int, discountPercent: float}|null
     */
    private function resolveMember(?int $membershipId, int $costPriceSen, float $packageMarkupPercent): ?array
    {
        if ($membershipId === null) {
            return null;
        }

        $membership = Membership::query()->with('membershipPlan')->find($membershipId);

        if ($membership === null || $membership->membershipPlan === null) {
            return null;
        }

        $discountPercent = (float) $membership->membershipPlan->discount_percent;
        $memberPriceSen = $this->membershipPricing->calculateMemberPrice(
            $costPriceSen,
            $packageMarkupPercent,
            $discountPercent,
        );

        if ($memberPriceSen > $membership->quota_remaining_sen) {
            return null;
        }

        return [
            'membershipId' => $membership->id,
            'memberPriceSen' => $memberPriceSen,
            'discountPercent' => $discountPercent,
        ];
    }
}
