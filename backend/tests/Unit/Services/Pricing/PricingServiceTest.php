<?php

namespace Tests\Unit\Services\Pricing;

use App\Services\Pricing\InvalidPricingConfigException;
use App\Services\Pricing\PricingService;
use PHPUnit\Framework\TestCase;

class PricingServiceTest extends TestCase
{
    /**
     * Worked example (independent of the implementation):
     * cost_price RM10.00, standard_selling_price RM12.00 (platform_profit RM2.00),
     * reseller_markup_pct 10% of standard_selling_price => RM1.20 reseller_profit,
     * selling_price RM13.20.
     */
    public function test_calculates_three_tier_breakdown_from_worked_example(): void
    {
        $service = new PricingService;

        $breakdown = $service->calculate(
            costPrice: 1000,
            standardSellingPrice: 1200,
            resellerMarkupPct: 10.0,
        );

        $this->assertSame(1000, $breakdown->costPrice);
        $this->assertSame(1200, $breakdown->standardSellingPrice);
        $this->assertSame(1320, $breakdown->sellingPrice);
        $this->assertSame(200, $breakdown->platformProfit);
        $this->assertSame(120, $breakdown->resellerProfit);
    }

    /**
     * MVP case (ADR-003): the single internal owner-reseller has 0% markup,
     * so selling_price collapses to standard_selling_price and reseller_profit
     * is zero — all margin is platform_profit.
     */
    public function test_zero_reseller_markup_collapses_selling_price_to_standard_selling_price(): void
    {
        $service = new PricingService;

        $breakdown = $service->calculate(
            costPrice: 1000,
            standardSellingPrice: 1200,
            resellerMarkupPct: 0.0,
        );

        $this->assertSame(1200, $breakdown->sellingPrice);
        $this->assertSame(200, $breakdown->platformProfit);
        $this->assertSame(0, $breakdown->resellerProfit);
    }

    /**
     * Business invariant: standard_selling_price below cost_price means the
     * platform is configured to lose money on every sale of this package —
     * this is always a config mistake (e.g. stale supplier price sync,
     * markup entered as a negative number), never a valid state.
     */
    public function test_rejects_standard_selling_price_below_cost_price(): void
    {
        $service = new PricingService;

        $this->expectException(InvalidPricingConfigException::class);

        $service->calculate(
            costPrice: 1000,
            standardSellingPrice: 900,
            resellerMarkupPct: 10.0,
        );
    }

    /**
     * ADR-056 decision 2, worked example: cost RM10.00, an active tier at
     * 5% markup over cost => wholesale base RM10.50 (platform_profit RM0.50),
     * reseller adds 10% of the base => RM1.05 reseller_profit, customer
     * pays RM11.55. standard_selling_price (RM13.00) is still reported as
     * the counterfactual guest price.
     */
    public function test_reseller_with_active_tier_prices_off_cost_price(): void
    {
        $service = new PricingService;

        $breakdown = $service->calculateForReseller(
            costPrice: 1000,
            standardSellingPrice: 1300,
            tierMarkupPct: 5.0,
            resellerMarkupPct: 10.0,
        );

        $this->assertSame(1000, $breakdown->costPrice);
        $this->assertSame(1300, $breakdown->standardSellingPrice);
        $this->assertSame(1155, $breakdown->sellingPrice);
        $this->assertSame(50, $breakdown->platformProfit);
        $this->assertSame(105, $breakdown->resellerProfit);
    }

    /**
     * ADR-056 decision 3: a reseller with no active/grace tier (lapsed, or
     * never subscribed) falls back to standard_selling_price as the base —
     * identical to calculate() with the same reseller markup. Platform
     * captures the full retail margin; the reseller still earns their own
     * markup, on the higher base.
     */
    public function test_reseller_without_a_tier_falls_back_to_standard_selling_price(): void
    {
        $service = new PricingService;

        $withoutTier = $service->calculateForReseller(
            costPrice: 1000,
            standardSellingPrice: 1300,
            tierMarkupPct: null,
            resellerMarkupPct: 10.0,
        );

        $plain = $service->calculate(
            costPrice: 1000,
            standardSellingPrice: 1300,
            resellerMarkupPct: 10.0,
        );

        $this->assertEquals($plain, $withoutTier);
        $this->assertSame(1430, $withoutTier->sellingPrice);
        $this->assertSame(300, $withoutTier->platformProfit);
        $this->assertSame(130, $withoutTier->resellerProfit);
    }

    /**
     * The lapse economics the founder reasoned through in the ADR-056
     * grill: for the same reseller markup, the lapsed base is worse for
     * the reseller (higher customer price, so less competitive) and better
     * for the platform (full retail margin, not the thin tier margin).
     */
    public function test_lapsed_reseller_yields_higher_platform_profit_and_higher_customer_price_than_active_tier(): void
    {
        $service = new PricingService;

        $active = $service->calculateForReseller(1000, 1300, 5.0, 10.0);
        $lapsed = $service->calculateForReseller(1000, 1300, null, 10.0);

        $this->assertGreaterThan($active->platformProfit, $lapsed->platformProfit);
        $this->assertGreaterThan($active->sellingPrice, $lapsed->sellingPrice);
    }

    /**
     * A 0% tier markup is a valid pure-function input (the ~3% floor is
     * enforced by the tier CRUD in ADR-058, not here): the wholesale base
     * collapses to cost_price and the platform earns nothing per unit.
     */
    public function test_zero_tier_markup_collapses_wholesale_base_to_cost_price(): void
    {
        $service = new PricingService;

        $breakdown = $service->calculateForReseller(
            costPrice: 1000,
            standardSellingPrice: 1300,
            tierMarkupPct: 0.0,
            resellerMarkupPct: 10.0,
        );

        $this->assertSame(0, $breakdown->platformProfit);
        $this->assertSame(1000, $breakdown->sellingPrice - $breakdown->resellerProfit);
    }

    public function test_reseller_pricing_rejects_standard_selling_price_below_cost_price(): void
    {
        $service = new PricingService;

        $this->expectException(InvalidPricingConfigException::class);

        $service->calculateForReseller(
            costPrice: 1000,
            standardSellingPrice: 900,
            tierMarkupPct: 5.0,
            resellerMarkupPct: 10.0,
        );
    }
}
