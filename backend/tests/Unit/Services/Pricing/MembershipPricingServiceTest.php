<?php

namespace Tests\Unit\Services\Pricing;

use App\Services\Pricing\MembershipPricingService;
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
        $service = new MembershipPricingService();

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
        $service = new MembershipPricingService();

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
        $service = new MembershipPricingService();

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
        $service = new MembershipPricingService();

        $memberPriceSen = $service->calculateMemberPrice(
            costPriceSen: 1000,
            packageMarkupPercent: 0.0,
            discountPercent: 50.0,
        );

        $this->assertSame(1000, $memberPriceSen);
    }
}
