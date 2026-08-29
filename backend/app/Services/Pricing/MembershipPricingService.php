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
    public function calculateMemberPrice(int $costPriceSen, float $packageMarkupPercent, float $discountPercent): int
    {
        $effectiveMarkupPercent = max(0.0, $packageMarkupPercent * (1 - $discountPercent / 100));

        return (int) round($costPriceSen * (1 + $effectiveMarkupPercent / 100));
    }
}
