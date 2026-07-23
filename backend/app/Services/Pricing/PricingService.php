<?php

namespace App\Services\Pricing;

final class PricingService
{
    public function calculate(
        int $costPrice,
        int $resellerCostPrice,
        float $resellerMarkupPct,
    ): PricingBreakdown {
        if ($resellerCostPrice < $costPrice) {
            throw new InvalidPricingConfigException(
                "reseller_cost_price ({$resellerCostPrice}) is below cost_price ({$costPrice})",
            );
        }

        $resellerMarkupAmount = (int) round($resellerCostPrice * $resellerMarkupPct / 100);
        $sellingPrice = $resellerCostPrice + $resellerMarkupAmount;

        return new PricingBreakdown(
            costPrice: $costPrice,
            resellerCostPrice: $resellerCostPrice,
            sellingPrice: $sellingPrice,
            platformProfit: $resellerCostPrice - $costPrice,
            resellerProfit: $resellerMarkupAmount,
        );
    }
}
