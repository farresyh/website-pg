<?php

namespace App\Services\Pricing;

use App\Models\Package;
use App\Models\PriceChangeLog;

/**
 * ADR-125 decisions 34/45: the one place a package's `cost_price` changes
 * with its `standard_selling_price` recomputed and a PriceChangeLog row
 * written. Price Sync (a run id), a pending-change approval (run id +
 * admin) and a manual package cost edit (admin, no run) all route here.
 * Combo recompute stays with the caller, which batches it per run.
 */
final class PackageCostService
{
    public function __construct(private readonly PackageMarkupService $markup) {}

    /**
     * @param  array<string, mixed>  $alsoSet  extra columns written in the same update
     */
    public function apply(Package $package, int $newCostPrice, ?int $priceSyncRunId, ?int $adminUserId = null, array $alsoSet = []): void
    {
        $newStandardSellingPrice = $this->markup->calculateStandardSellingPrice($newCostPrice, (float) $package->markup_percent);

        PriceChangeLog::query()->create([
            'price_sync_run_id' => $priceSyncRunId,
            'admin_user_id' => $adminUserId,
            'package_id' => $package->id,
            'old_cost_price' => $package->cost_price,
            'new_cost_price' => $newCostPrice,
            'old_standard_selling_price' => $package->standard_selling_price,
            'new_standard_selling_price' => $newStandardSellingPrice,
        ]);

        $package->update([
            'cost_price' => $newCostPrice,
            'standard_selling_price' => $newStandardSellingPrice,
            ...$alsoSet,
        ]);
    }
}
