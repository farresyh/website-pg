<?php

namespace App\Services\Pricing;

use App\Models\Membership;

/**
 * Decides which price an Order is charged at across every product-sale
 * channel, and returns one PricingResolution the caller consumes whole —
 * CheckoutService (initiate + previewTotal) and ResellerOrderPlacementService
 * no longer branch on the pricing basis themselves, and the three inline
 * `PricingService::calculateForAffiliate(…, 0.0)` copies in the wallet /
 * bot / API channels are gone.
 *
 * Two channel-explicit entry points, mirroring `PricingService`'s own
 * `calculate()` vs `calculateForAffiliate()` split (ADR-060 PR-4b):
 *
 *  - resolveStorefront()      the guest storefront 2×2 —
 *       · Member          a valid, in-quota membership was resolved. Member
 *                         price overrides everything; `affiliateProfit` is
 *                         0 and `normalSellingPrice` records the non-member
 *                         price.
 *       · Affiliate        `$tierMarkupPct` is non-null — a third-party
 *                         affiliate storefront order priced against an
 *                         active/grace wholesale tier (ADR-056).
 *       · Standard         no membership, no tier — the plain guest chain.
 *       · Affiliate-lapsed `$tierMarkupPct` comes through null, so this
 *                         resolves to the exact same Standard path a guest
 *                         order uses (ADR-060 2026-09-05 addendum §3) — not
 *                         its own basis.
 *
 *  - resolveResellerWallet()  the prepaid-wallet channel (Reseller API +
 *       Bot, ADR-073) — wholesale base `cost × (1 + tier%)`, no affiliate
 *       margin layered on, `basis = ResellerWallet`.
 *
 * Membership subscription is deliberately NOT a channel here — it is a
 * subscription fee, not a product sale (`MembershipCheckoutAttempt`,
 * `membership_fee` ledger credit), and shares only the ledger + gateway.
 *
 * This is an unlocked read that only *decides* the price. The locked
 * commits (membership quota decrement, voucher redemption) stay in
 * CheckoutService at the trust points VoucherService::redeem() already
 * uses.
 */
final class OrderPricingResolver
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly MembershipPricingService $membershipPricing,
    ) {}

    /**
     * @param  float|null  $tierMarkupPct  the storefront brand's active
     *                                     wholesale tier markup (`Affiliate::wholesaleTierMarkupPct()`),
     *                                     or null when the brand has no tier / the tier lapsed. Null is
     *                                     behaviourally identical to the pre-ADR-060 guest chain.
     */
    public function resolveStorefront(
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
            wholesaleMarkupPct: $tierMarkupPct,
        );
    }

    /**
     * The prepaid-wallet channel. `$tierMarkupPct` is never null here — a
     * Reseller with no assigned tier is rejected upstream
     * (`NoResellerTierAssignedException`), it never reaches pricing.
     * `calculateForAffiliate(…, 0.0)` yields `affiliateProfit = 0` by
     * construction — ADR-073 decision 6's "no reseller_profit line" is not
     * a branch to remember.
     */
    public function resolveResellerWallet(
        int $costPriceSen,
        int $standardSellingPriceSen,
        float $tierMarkupPct,
    ): PricingResolution {
        $breakdown = $this->pricing->calculateForAffiliate(
            $costPriceSen,
            $standardSellingPriceSen,
            $tierMarkupPct,
            0.0,
        );

        return new PricingResolution(
            costPriceSen: $breakdown->costPrice,
            standardSellingPriceSen: $breakdown->standardSellingPrice,
            sellingPriceSen: $breakdown->sellingPrice,
            platformProfitSen: $breakdown->platformProfit,
            affiliateProfitSen: $breakdown->affiliateProfit,
            basis: PricingBasis::ResellerWallet,
            wholesaleMarkupPct: $tierMarkupPct,
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
