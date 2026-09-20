<?php

namespace App\Services\Pricing;

use App\Models\Affiliate;
use App\Models\Package;

/**
 * ADR-027 decision 5, narrowed by its 2026-08-29 addendum decision 16:
 * a member price is a package's own markup_percent reduced by a
 * membership tier's discount_percent, floored at 0% — never a flat
 * member-wide markup override, and never sold below cost_price.
 * Deliberately a separate seam from PricingService (the cost ->
 * standard-selling-price -> selling-price chain, ADR-013) — membership discount
 * is a distinct concern layered on top of a package's own markup, not
 * an affiliate-tier concept. Reuses PackageMarkupService's calculation
 * shape (cost x (1 + markup%)) rather than sharing its instance.
 */
final class MembershipPricingService
{
    public function __construct(private readonly PricingService $pricing) {}

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

    /**
     * 2026-09-20 addendum — the one representative package a hypothetical
     * discount_percent is evaluated against, both for the admin preview
     * (`MembershipPlanController::preview()`, extracted here so it's no
     * longer duplicated) and the public `GET /api/membership/subscribe-
     * options` real-savings badge. The median-priced active package
     * (not cheapest/priciest), same "one worked example, not an edge
     * case" reasoning the admin preview was already built with.
     */
    public function representativePackage(): ?Package
    {
        $active = Package::query()->where('is_active', true)->orderBy('cost_price')->get();

        if ($active->isEmpty()) {
            return null;
        }

        return $active[intdiv($active->count(), 2)];
    }

    /**
     * 2026-09-20 addendum — the real, price-level savings percent a
     * hypothetical discount_percent works out to on a real package,
     * relative to what a guest actually pays (`PricingService::calculate()`
     * — the same call `CatalogController::sellingPriceSen()` makes, so
     * this can never drift from the real guest price). This is the
     * number customer-facing copy must show, never `discount_percent`
     * itself (a "% cut off markup" config value, not a price-level
     * savings figure — the mismatch that started this addendum).
     */
    public function realSavingsPercent(Package $package, float $discountPercent): float
    {
        $normalPriceSen = $this->pricing->calculate(
            $package->cost_price,
            $package->standard_selling_price,
            (float) Affiliate::primary()->markup_pct,
        )->sellingPrice;

        if ($normalPriceSen <= 0) {
            return 0.0;
        }

        $memberPriceSen = $this->calculateMemberPrice($package->cost_price, (float) $package->markup_percent, $discountPercent);

        return round((1 - $memberPriceSen / $normalPriceSen) * 100, 1);
    }
}
