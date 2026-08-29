<?php

namespace App\Services\Pricing;

final class PricingService
{
    public function calculate(
        int $costPrice,
        int $standardSellingPrice,
        float $resellerMarkupPct,
    ): PricingBreakdown {
        if ($standardSellingPrice < $costPrice) {
            throw new InvalidPricingConfigException(
                "standard_selling_price ({$standardSellingPrice}) is below cost_price ({$costPrice})",
            );
        }

        $resellerMarkupAmount = (int) round($standardSellingPrice * $resellerMarkupPct / 100);
        $sellingPrice = $standardSellingPrice + $resellerMarkupAmount;

        return new PricingBreakdown(
            costPrice: $costPrice,
            standardSellingPrice: $standardSellingPrice,
            sellingPrice: $sellingPrice,
            platformProfit: $standardSellingPrice - $costPrice,
            resellerProfit: $resellerMarkupAmount,
        );
    }
}
