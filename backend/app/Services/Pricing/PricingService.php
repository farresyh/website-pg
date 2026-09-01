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

    /**
     * ADR-056 (grilled 2026-08-30) decisions 2–4: pricing for a real
     * third-party reseller's branded storefront order.
     *
     * The wholesale base the platform charges the reseller is anchored on
     * supplier `cost_price`, not on `standardSellingPrice`:
     *   - active/grace tier subscription: base = cost × (1 + tierMarkupPct/100)
     *   - no active tier ($tierMarkupPct === null): base = standardSellingPrice
     *     (the same price a guest pays — ADR-056 decision 3's lapse fallback,
     *     not a penalty, self-corrects when the reseller pays their fee).
     *
     * The reseller then adds their own margin on top (`resellerMarkupPct`,
     * within their admin-set `max_markup_pct`), giving the customer-facing
     * price. `platformProfit` is the wholesale base minus cost (the tier
     * markup, or the full retail markup when lapsed); `resellerProfit` is
     * the reseller's own margin amount. `standardSellingPrice` is still
     * reported unchanged as the counterfactual guest price (ORD-9 snapshot).
     */
    public function calculateForReseller(
        int $costPrice,
        int $standardSellingPrice,
        ?float $tierMarkupPct,
        float $resellerMarkupPct,
    ): PricingBreakdown {
        if ($standardSellingPrice < $costPrice) {
            throw new InvalidPricingConfigException(
                "standard_selling_price ({$standardSellingPrice}) is below cost_price ({$costPrice})",
            );
        }

        if ($tierMarkupPct === null) {
            return $this->calculate($costPrice, $standardSellingPrice, $resellerMarkupPct);
        }

        $wholesaleBase = (int) round($costPrice * (1 + $tierMarkupPct / 100));
        $resellerMarkupAmount = (int) round($wholesaleBase * $resellerMarkupPct / 100);

        return new PricingBreakdown(
            costPrice: $costPrice,
            standardSellingPrice: $standardSellingPrice,
            sellingPrice: $wholesaleBase + $resellerMarkupAmount,
            platformProfit: $wholesaleBase - $costPrice,
            resellerProfit: $resellerMarkupAmount,
        );
    }
}
