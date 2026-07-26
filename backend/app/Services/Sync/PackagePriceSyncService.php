<?php

namespace App\Services\Sync;

use App\Models\Package;
use App\Models\PriceChangeLog;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Pricing\PackageMarkupService;
use Illuminate\Support\Carbon;

/**
 * ADR-015's ongoing-maintenance mechanism — runs immediately after
 * ProductSyncService::sync() (Price Sync's own Stage 1, unchanged).
 * This class has no stage number of its own: "Stage 2"/"Stage 3"
 * are already claimed elsewhere in this codebase for promote-to-
 * catalog and the deferred cheapest-by-name storefront resolution.
 *
 * Uses Stage 1's own `syncedAt` to tell which `supplier_products`
 * rows were actually touched this run apart from ones that are stale
 * — a Package whose `supplier_package_ref` has no supplier_products
 * row touched this run has vanished from the latest full catalog,
 * which ADR-015 decision #3 treats identically to an explicit
 * inactive status.
 *
 * Two independent mechanisms, deliberately not gated on each other:
 * - **Price Propagation** (decision #2) applies to every promoted
 *   Package seen this run, regardless of `is_active` or
 *   `deactivated_reason` — the entire point is to stop losing margin
 *   silently, so nothing is exempt from it.
 * - **Deactivation Detection** (decision #3/#4) only ever turns a
 *   Package *off*; it never reactivates one (that's the Pending
 *   Reactivation queue, computed live elsewhere) and it never touches
 *   a row an admin already turned off (`deactivated_reason ===
 *   'admin'`) — both invariants are already implied by "already
 *   `is_active === false`", so a single guard covers them.
 */
final class PackagePriceSyncService
{
    public function __construct(private readonly PackageMarkupService $markup)
    {
    }

    public function apply(Supplier $supplier, Carbon $syncedAt, ?int $priceSyncRunId): PackagePriceSyncResult
    {
        $packages = Package::query()->where('supplier_id', $supplier->id)->get();

        $supplierProducts = SupplierProduct::query()
            ->where('supplier_id', $supplier->id)
            ->whereIn('external_ref', $packages->pluck('supplier_package_ref'))
            ->get()
            ->keyBy('external_ref');

        $priceChanged = 0;
        $deactivated = 0;
        $affectedGameIds = [];

        foreach ($packages as $package) {
            $product = $supplierProducts->get($package->supplier_package_ref);
            // Compare by whole-second timestamp, not Carbon::equalTo():
            // a `datetime` column (sqlite and MySQL alike, absent
            // explicit fractional-seconds precision) truncates
            // microseconds on write, so the in-memory $syncedAt this
            // run started with would otherwise never match what was
            // actually persisted for a row touched this same run.
            $seenThisRun = $product && $product->last_synced_at?->timestamp === $syncedAt->timestamp;

            if (! $seenThisRun) {
                if ($this->deactivate($package)) {
                    $deactivated++;
                    $affectedGameIds[] = $package->game_id;
                }

                continue;
            }

            if ($product->price_sen !== null && $product->price_sen !== $package->cost_price) {
                $this->propagatePrice($package, $product->price_sen, $priceSyncRunId);
                $priceChanged++;
                $affectedGameIds[] = $package->game_id;
            }

            if ($product->status_raw !== 'active' && $this->deactivate($package)) {
                $deactivated++;
                $affectedGameIds[] = $package->game_id;
            }
        }

        return new PackagePriceSyncResult(
            priceChanged: $priceChanged,
            deactivated: $deactivated,
            affectedGameIds: array_values(array_unique($affectedGameIds)),
        );
    }

    private function propagatePrice(Package $package, int $newCostPrice, ?int $priceSyncRunId): void
    {
        $newResellerCostPrice = $this->markup->calculateResellerCostPrice($newCostPrice, (float) $package->markup_percent);

        PriceChangeLog::query()->create([
            'price_sync_run_id' => $priceSyncRunId,
            'package_id' => $package->id,
            'old_cost_price' => $package->cost_price,
            'new_cost_price' => $newCostPrice,
            'old_reseller_cost_price' => $package->reseller_cost_price,
            'new_reseller_cost_price' => $newResellerCostPrice,
        ]);

        $package->update([
            'cost_price' => $newCostPrice,
            'reseller_cost_price' => $newResellerCostPrice,
        ]);
    }

    private function deactivate(Package $package): bool
    {
        if (! $package->is_active) {
            return false;
        }

        $package->update([
            'is_active' => false,
            'deactivated_reason' => 'supplier_sync',
            'deactivated_at' => now(),
        ]);

        return true;
    }
}
