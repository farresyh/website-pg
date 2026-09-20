<?php

namespace Tests\Unit\Services\Pricing;

use App\Services\Pricing\MembershipPricingService;
use App\Services\Pricing\PricingService;
use PHPUnit\Framework\TestCase;

class MembershipPricingServiceTest extends TestCase
{
    /**
     * Worked example (ADR-027 decision 5): a package's own markup_percent
     * (15%) reduced by a tier's discount_percent (80%) => 3% effective
     * markup. cost_price RM10.00 => member_price RM10.30.
     */
    public function test_reduces_package_markup_by_tier_discount_percent(): void
    {
        $service = new MembershipPricingService(new PricingService);

        $memberPriceSen = $service->calculateMemberPrice(
            costPriceSen: 1000,
            packageMarkupPercent: 15.0,
            discountPercent: 80.0,
        );

        $this->assertSame(1030, $memberPriceSen);
    }

    /**
     * Decision 5: floored at 0% — a member price can never fall below
     * cost_price, even if a 100% discount is configured.
     */
    public function test_floors_at_zero_percent_markup_when_discount_is_100_percent(): void
    {
        $service = new MembershipPricingService(new PricingService);

        $memberPriceSen = $service->calculateMemberPrice(
            costPriceSen: 1000,
            packageMarkupPercent: 15.0,
            discountPercent: 100.0,
        );

        $this->assertSame(1000, $memberPriceSen);
    }

    /**
     * A discount_percent above 100 must not push the effective markup
     * negative (which would sell below cost_price) — the floor clamps
     * it the same as exactly 100%.
     */
    public function test_floors_at_zero_percent_markup_when_discount_exceeds_100_percent(): void
    {
        $service = new MembershipPricingService(new PricingService);

        $memberPriceSen = $service->calculateMemberPrice(
            costPriceSen: 1000,
            packageMarkupPercent: 15.0,
            discountPercent: 150.0,
        );

        $this->assertSame(1000, $memberPriceSen);
    }

    /**
     * A package already at 0% markup stays at cost_price regardless of
     * the tier's discount_percent — nothing left to discount.
     */
    public function test_zero_package_markup_collapses_member_price_to_cost_price(): void
    {
        $service = new MembershipPricingService(new PricingService);

        $memberPriceSen = $service->calculateMemberPrice(
            costPriceSen: 1000,
            packageMarkupPercent: 0.0,
            discountPercent: 50.0,
        );

        $this->assertSame(1000, $memberPriceSen);
    }

    /**
     * Exposed as its own method (founder ask, 2026-08-29): the admin
     * preview shows this breakdown explicitly (package markup -> tier
     * discount -> effective markup), not just the final RM prices —
     * calculateMemberPrice() must compute from the exact same number,
     * never a second derivation of the formula.
     */
    public function test_effective_markup_percent_matches_the_worked_example(): void
    {
        $service = new MembershipPricingService(new PricingService);

        $this->assertSame(3.0, $service->effectiveMarkupPercent(15.0, 80.0));
    }

    public function test_effective_markup_percent_floors_at_zero(): void
    {
        $service = new MembershipPricingService(new PricingService);

        $this->assertSame(0.0, $service->effectiveMarkupPercent(15.0, 150.0));
    }
}
