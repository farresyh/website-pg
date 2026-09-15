<?php

namespace App\Services\Sync;

use App\Models\DeactivationLog;
use App\Models\Package;
use App\Models\PendingPriceChange;
use App\Models\PriceChangeLog;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Pricing\ComboPricingService;
use App\Services\Pricing\PackageMarkupService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 *
 * A third mechanism, ADR-025, sits inside Price Propagation itself
 * rather than beside it: `evaluatePriceChange()` is consulted before
 * every write to `cost_price`, either rejecting an invalid supplier
 * price outright (floor) or diverting a too-large swing into the
 * `PendingPriceChange` review queue instead of applying it (see that
 * ADR for the full floor/swing design).
 */
final class PackagePriceSyncService
{
    public function __construct(
        private readonly PackageMarkupService $markup,
        private readonly ComboPricingService $comboPricing,
    ) {}

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
        $floorRejected = 0;
        $anomaliesFlagged = 0;
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
                if ($this->deactivate($package, $priceSyncRunId)) {
                    $deactivated++;
                    $affectedGameIds[] = $package->game_id;
                    $affectedGameIds = [...$affectedGameIds, ...$this->comboPricing->cascadeDeactivate($package, $priceSyncRunId)->all()];
                }

                continue;
            }

            if ($product->price_sen !== null && $product->price_sen !== $package->cost_price) {
                $outcome = $this->evaluatePriceChange($package, $product->price_sen, $priceSyncRunId);

                match ($outcome) {
                    'applied' => $priceChanged++,
                    'anomaly_flagged' => $anomaliesFlagged++,
                    'floor_rejected' => $floorRejected++,
                    'skipped' => null,
                };

                if ($outcome === 'applied' || $outcome === 'anomaly_flagged') {
                    $affectedGameIds[] = $package->game_id;
                }

                if ($outcome === 'applied') {
                    // ADR-094 decision 6: this component's cost_price
                    // actually moved — recompute every active combo
                    // that references it (decision 5's stored,
                    // Price-Sync-cadence pricing), not just its own row.
                    $affectedGameIds = [...$affectedGameIds, ...$this->comboPricing->recomputeForComponentChange($package, $priceSyncRunId)->all()];
                }
            }

            if ($product->status_raw !== 'active' && $this->deactivate($package, $priceSyncRunId)) {
                $deactivated++;
                $affectedGameIds[] = $package->game_id;
                $affectedGameIds = [...$affectedGameIds, ...$this->comboPricing->cascadeDeactivate($package, $priceSyncRunId)->all()];
            }
        }

        return new PackagePriceSyncResult(
            priceChanged: $priceChanged,
            deactivated: $deactivated,
            affectedGameIds: array_values(array_unique($affectedGameIds)),
            floorRejected: $floorRejected,
            anomaliesFlagged: $anomaliesFlagged,
        );
    }

    /**
     * ADR-025 decisions #1-#3: a floor violation (`<= 0`) is an
     * unconditional hard auto-reject; a swing past the configured
     * threshold blocks the write and queues a PendingPriceChange
     * instead of applying it; anything else propagates normally. A
     * package with no prior cost_price (`0`, legacy data predating
     * this guard) has no safe baseline to diff a percentage against —
     * applies directly, same reasoning `promote()`'s own first-time
     * creation already uses.
     *
     * @return 'applied'|'floor_rejected'|'anomaly_flagged'|'skipped'
     */
    private function evaluatePriceChange(Package $package, int $newCostPrice, ?int $priceSyncRunId): string
    {
        if ($newCostPrice <= 0) {
            Log::error('Price Sync: rejected a non-positive supplier price', [
                'package_id' => $package->id,
                'price_sen' => $newCostPrice,
            ]);

            return 'floor_rejected';
        }

        if ($package->cost_price > 0) {
            $swingPercent = abs($newCostPrice - $package->cost_price) / $package->cost_price * 100;
            $threshold = (float) config('packages.price_swing_threshold_percent');

            if ($swingPercent > $threshold) {
                // Decision #5's "skip if already pending" check and the
                // row it writes must be atomic — without a lock, two
                // sync runs evaluating this package at the same moment
                // (e.g. an overlapping scheduled run + a manual "Sync
                // All Prices Now" click) could both pass the exists()
                // check before either inserts, producing two open
                // PendingPriceChange rows for one package. Locking the
                // Package row itself (not a new table-specific lock)
                // serializes any concurrent evaluation of this package,
                // matching this codebase's existing row-lock convention
                // (LedgerService::withdraw()/VoucherService::redeem()).
                return DB::transaction(function () use ($package, $newCostPrice, $priceSyncRunId) {
                    Package::query()->whereKey($package->id)->lockForUpdate()->firstOrFail();

                    if (PendingPriceChange::query()->where('package_id', $package->id)->where('status', 'pending')->exists()) {
                        return 'skipped';
                    }

                    $this->flagPriceAnomaly($package, $newCostPrice, $priceSyncRunId);

                    return 'anomaly_flagged';
                });
            }
        }

        $this->propagatePrice($package, $newCostPrice, $priceSyncRunId);

        return 'applied';
    }

    private function flagPriceAnomaly(Package $package, int $proposedCostPrice, ?int $priceSyncRunId): void
    {
        $proposedStandardSellingPrice = $this->markup->calculateStandardSellingPrice($proposedCostPrice, (float) $package->markup_percent);

        PendingPriceChange::query()->create([
            'price_sync_run_id' => $priceSyncRunId,
            'package_id' => $package->id,
            'old_cost_price' => $package->cost_price,
            'proposed_cost_price' => $proposedCostPrice,
            'old_standard_selling_price' => $package->standard_selling_price,
            'proposed_standard_selling_price' => $proposedStandardSellingPrice,
        ]);

        Log::warning('Price Sync: flagged a price swing anomaly', [
            'package_id' => $package->id,
            'old_cost_price' => $package->cost_price,
            'proposed_cost_price' => $proposedCostPrice,
        ]);

        // Mirrors deactivate()'s own "never touches a package the
        // admin already deactivated" invariant: only take the package
        // offline here if it was this sync that's putting it in that
        // state, never silently overwrite an admin's own
        // deactivated_reason (e.g. 'admin') with 'price_anomaly'.
        if ($package->is_active) {
            $package->update([
                'is_active' => false,
                'deactivated_reason' => 'price_anomaly',
                'deactivated_at' => now(),
            ]);
        }
    }

    private function propagatePrice(Package $package, int $newCostPrice, ?int $priceSyncRunId): void
    {
        $newStandardSellingPrice = $this->markup->calculateStandardSellingPrice($newCostPrice, (float) $package->markup_percent);

        PriceChangeLog::query()->create([
            'price_sync_run_id' => $priceSyncRunId,
            'package_id' => $package->id,
            'old_cost_price' => $package->cost_price,
            'new_cost_price' => $newCostPrice,
            'old_standard_selling_price' => $package->standard_selling_price,
            'new_standard_selling_price' => $newStandardSellingPrice,
        ]);

        $package->update([
            'cost_price' => $newCostPrice,
            'standard_selling_price' => $newStandardSellingPrice,
        ]);
    }

    private function deactivate(Package $package, ?int $priceSyncRunId): bool
    {
        if (! $package->is_active) {
            return false;
        }

        $package->update([
            'is_active' => false,
            'deactivated_reason' => 'supplier_sync',
            'deactivated_at' => now(),
        ]);

        DeactivationLog::query()->create([
            'price_sync_run_id' => $priceSyncRunId,
            'package_id' => $package->id,
        ]);

        return true;
    }
}
