<?php

namespace Tests\Unit\Services\Pricing;

use App\Services\Pricing\InvalidPricingConfigException;
use App\Services\Pricing\PricingService;
use PHPUnit\Framework\TestCase;

class PricingServiceTest extends TestCase
{
    /**
     * Worked example (independent of the implementation):
     * cost_price RM10.00, reseller_cost_price RM12.00 (platform_profit RM2.00),
     * reseller_markup_pct 10% of reseller_cost_price => RM1.20 reseller_profit,
     * selling_price RM13.20.
     */
    public function test_calculates_three_tier_breakdown_from_worked_example(): void
    {
        $service = new PricingService();

        $breakdown = $service->calculate(
            costPrice: 1000,
            resellerCostPrice: 1200,
            resellerMarkupPct: 10.0,
        );

        $this->assertSame(1000, $breakdown->costPrice);
        $this->assertSame(1200, $breakdown->resellerCostPrice);
        $this->assertSame(1320, $breakdown->sellingPrice);
        $this->assertSame(200, $breakdown->platformProfit);
        $this->assertSame(120, $breakdown->resellerProfit);
    }

    /**
     * MVP case (ADR-003): the single internal owner-reseller has 0% markup,
     * so selling_price collapses to reseller_cost_price and reseller_profit
     * is zero — all margin is platform_profit.
     */
    public function test_zero_reseller_markup_collapses_selling_price_to_reseller_cost_price(): void
    {
        $service = new PricingService();

        $breakdown = $service->calculate(
            costPrice: 1000,
            resellerCostPrice: 1200,
            resellerMarkupPct: 0.0,
        );

        $this->assertSame(1200, $breakdown->sellingPrice);
        $this->assertSame(200, $breakdown->platformProfit);
        $this->assertSame(0, $breakdown->resellerProfit);
    }

    /**
     * Business invariant: reseller_cost_price below cost_price means the
     * platform is configured to lose money on every sale of this package —
     * this is always a config mistake (e.g. stale supplier price sync,
     * markup entered as a negative number), never a valid state.
     */
    public function test_rejects_reseller_cost_price_below_cost_price(): void
    {
        $service = new PricingService();

        $this->expectException(InvalidPricingConfigException::class);

        $service->calculate(
            costPrice: 1000,
            resellerCostPrice: 900,
            resellerMarkupPct: 10.0,
        );
    }
}
