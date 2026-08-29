<?php

namespace App\Services\Pricing;

/**
 * ADR-027 decision 5, narrowed by its 2026-08-29 addendum decision 16:
 * a member price is a package's own markup_percent reduced by a
 * membership tier's discount_percent, floored at 0% — never a flat
 * member-wide markup override, and never sold below cost_price.
 * Deliberately a separate seam from PricingService (the cost ->
 * reseller-cost -> selling-price chain, ADR-013) — membership discount
 * is a distinct concern layered on top of a package's own markup, not
 * a reseller-tier concept. Reuses PackageMarkupService's calculation
 * shape (cost x (1 + markup%)) rather than sharing its instance.
 */
final class MembershipPricingService
{
    /**
     * Exposed as its own method, not just an inline step of
     * calculateMemberPrice(): the admin preview (founder ask, 2026-08-29)
     * shows this exact number to explain "package markup -> tier
     * discount -> effective markup" before a discount% is saved.
     */
    public function effectiveMarkupPercent(float $packageMarkupPercent, float $discountPercent): float
    {
        // Rounded to 2dp — matches markup_percent/discount_percent's own
        // decimal(*, 2) DB precision, and avoids float noise (e.g.
        // 15.0 * (1 - 0.8) landing on 2.999999999999999, not 3.0).
        return round(max(0.0, $packageMarkupPercent * (1 - $discountPercent / 100)), 2);
    }

    public function calculateMemberPrice(int $costPriceSen, float $packageMarkupPercent, float $discountPercent): int
    {
        $effectiveMarkupPercent = $this->effectiveMarkupPercent($packageMarkupPercent, $discountPercent);

        return (int) round($costPriceSen * (1 + $effectiveMarkupPercent / 100));
    }
}
