<?php

namespace App\Services\Pricing;

/**
 * GAME-7 (founder revision, 2026-07-25): the single place
 * `reseller_cost_price` is ever computed from `cost_price` +
 * `markup_percent` — used both at promote time (default markup,
 * SupplierProductController) and whenever admin updates a package's
 * markup (PackageController::updateMarkup). `reseller_cost_price`
 * stays a stored column (not computed live at order time, per PRD
 * §8's Package definition) — this service is what keeps it in sync
 * with `markup_percent` whenever either changes.
 */
final class PackageMarkupService
{
    public function calculateResellerCostPrice(int $costPriceSen, float $markupPercent): int
    {
        return (int) round($costPriceSen * (1 + $markupPercent / 100));
    }
}
