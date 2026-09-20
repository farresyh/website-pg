<?php

namespace Tests\Feature\Services\Pricing;

use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Services\Pricing\MembershipPricingService;
use App\Services\Pricing\OrderPricingResolver;
use App\Services\Pricing\PricingBasis;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-060 PR-1 + PR-4b: the one seam that decides an Order's price across
 * the product-sale channels. `resolveStorefront()` covers the guest 2×2
 * (standard / member / affiliate-tier / affiliate-lapsed);
 * `resolveResellerWallet()` covers the prepaid-wallet channel. These lock
 * in the exact behaviour the callers had inline before.
 */
class OrderPricingResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): OrderPricingResolver
    {
        return new OrderPricingResolver(new PricingService, new MembershipPricingService(new PricingService));
    }

    public function test_no_membership_and_no_tier_resolves_standard(): void
    {
        $resolution = $this->resolver()->resolveStorefront(
            costPriceSen: 900,
            standardSellingPriceSen: 1000,
            packageMarkupPercent: 11.0,
            affiliateMarkupPct: 0.0,
            tierMarkupPct: null,
            membershipId: null,
        );

        $this->assertSame(PricingBasis::Standard, $resolution->basis);
        $this->assertSame(1000, $resolution->sellingPriceSen);
        $this->assertSame(100, $resolution->platformProfitSen);
        $this->assertSame(0, $resolution->affiliateProfitSen);
        $this->assertNull($resolution->normalSellingPriceSen);
        $this->assertNull($resolution->membershipId);
        $this->assertNull($resolution->memberDiscountPercent);
    }

    /**
     * A third-party affiliate whose wholesale tier has lapsed comes
     * through with tierMarkupPct === null — priced on the exact Standard
     * path (their own markup still applies on top of standard retail),
     * pricing_basis stays 'standard' (ADR-060 2026-09-05 addendum d3).
     */
    public function test_affiliate_markup_without_an_active_tier_still_resolves_standard_basis(): void
    {
        $resolution = $this->resolver()->resolveStorefront(
            costPriceSen: 900,
            standardSellingPriceSen: 1000,
            packageMarkupPercent: 0.0,
            affiliateMarkupPct: 10.0,
            tierMarkupPct: null,
            membershipId: null,
        );

        $this->assertSame(PricingBasis::Standard, $resolution->basis);
        $this->assertSame(1100, $resolution->sellingPriceSen); // 1000 + 10%
        $this->assertSame(100, $resolution->platformProfitSen); // 1000 - 900
        $this->assertSame(100, $resolution->affiliateProfitSen);
        $this->assertNull($resolution->wholesaleMarkupPct); // no tier -> not snapshotted
    }

    public function test_an_active_tier_resolves_affiliate_basis_with_wholesale_pricing(): void
    {
        $resolution = $this->resolver()->resolveStorefront(
            costPriceSen: 1000,
            standardSellingPriceSen: 1500,
            packageMarkupPercent: 0.0,
            affiliateMarkupPct: 10.0,
            tierMarkupPct: 20.0,
            membershipId: null,
        );

        $this->assertSame(PricingBasis::Affiliate, $resolution->basis);
        $this->assertSame(1320, $resolution->sellingPriceSen); // wholesale 1200 + 10% affiliate margin
        $this->assertSame(200, $resolution->platformProfitSen); // wholesale 1200 - cost 1000
        $this->assertSame(120, $resolution->affiliateProfitSen);
        $this->assertSame(1500, $resolution->standardSellingPriceSen); // counterfactual guest price, unchanged
        $this->assertSame(20.0, $resolution->wholesaleMarkupPct); // the tier markup, snapshotted for resend
    }

    public function test_resolve_reseller_wallet_prices_at_the_tier_rate_with_no_affiliate_margin(): void
    {
        $resolution = $this->resolver()->resolveResellerWallet(
            costPriceSen: 1000,
            standardSellingPriceSen: 1500,
            tierMarkupPct: 20.0,
        );

        $this->assertSame(PricingBasis::ResellerWallet, $resolution->basis);
        $this->assertSame(1200, $resolution->sellingPriceSen); // wholesale base only, no margin
        $this->assertSame(200, $resolution->platformProfitSen); // 1200 - 1000
        $this->assertSame(0, $resolution->affiliateProfitSen);
        $this->assertSame(1500, $resolution->standardSellingPriceSen);
        $this->assertSame(20.0, $resolution->wholesaleMarkupPct);
        $this->assertNull($resolution->membershipId);
        $this->assertNull($resolution->normalSellingPriceSen);
    }

    public function test_an_in_quota_member_resolves_member_basis(): void
    {
        $membership = $this->activeMembership(quotaRemainingSen: 2000);

        $resolution = $this->resolver()->resolveStorefront(
            costPriceSen: 1000,
            standardSellingPriceSen: 1200,
            packageMarkupPercent: 15.0,
            affiliateMarkupPct: 0.0,
            tierMarkupPct: null,
            membershipId: $membership->id,
        );

        $this->assertSame(PricingBasis::Member, $resolution->basis);
        // 80% off 15% package markup -> 3% effective -> 1000 * 1.03
        $this->assertSame(1030, $resolution->sellingPriceSen);
        $this->assertSame(30, $resolution->platformProfitSen);
        $this->assertSame(0, $resolution->affiliateProfitSen);
        $this->assertSame(1200, $resolution->normalSellingPriceSen); // the non-member price
        $this->assertSame($membership->id, $resolution->membershipId);
        $this->assertSame(80.0, $resolution->memberDiscountPercent);
        $this->assertNull($resolution->wholesaleMarkupPct); // member price is not tier-derived
        // ADR-105 decision 3 — the package's own markup_percent, frozen
        // for a later resend to reconcile against instead of whatever
        // the package's markup_percent happens to be by then.
        $this->assertSame(15.0, $resolution->markupPercent);
    }

    public function test_a_member_priced_out_of_quota_falls_back_to_standard(): void
    {
        $membership = $this->activeMembership(quotaRemainingSen: 1);

        $resolution = $this->resolver()->resolveStorefront(
            costPriceSen: 1000,
            standardSellingPriceSen: 1200,
            packageMarkupPercent: 15.0,
            affiliateMarkupPct: 0.0,
            tierMarkupPct: null,
            membershipId: $membership->id,
        );

        $this->assertSame(PricingBasis::Standard, $resolution->basis);
        $this->assertSame(1200, $resolution->sellingPriceSen);
        $this->assertNull($resolution->membershipId);
        $this->assertNull($resolution->memberDiscountPercent);
        $this->assertNull($resolution->normalSellingPriceSen);
    }

    public function test_an_unknown_membership_id_falls_back_to_standard(): void
    {
        $resolution = $this->resolver()->resolveStorefront(
            costPriceSen: 1000,
            standardSellingPriceSen: 1200,
            packageMarkupPercent: 15.0,
            affiliateMarkupPct: 0.0,
            tierMarkupPct: null,
            membershipId: 999999,
        );

        $this->assertSame(PricingBasis::Standard, $resolution->basis);
        $this->assertNull($resolution->membershipId);
    }

    private function activeMembership(int $quotaRemainingSen): Membership
    {
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail(); // 80% off (seeded by migration)

        return Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => $quotaRemainingSen,
            'expires_at' => now()->addDays(20),
        ]);
    }
}
