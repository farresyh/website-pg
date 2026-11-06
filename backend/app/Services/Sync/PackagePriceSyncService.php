<?php

namespace App\Services\Sync;

use App\Models\Package;
use App\Models\PendingPriceChange;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Backup\BackupFailureAlerter;
use App\Services\Catalog\PackageActivation;
use App\Services\Pricing\ComboPricingService;
use App\Services\Pricing\PackageCostService;
use App\Services\Pricing\PackageMarkupService;
use App\Services\Supplier\SupplierPackageStatusService;
use App\Support\MalaysiaTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
 * A third mechanism sits inside Price Propagation itself rather than
 * beside it: `evaluatePriceChange()` is consulted before every write to
 * `cost_price`. It rejects an invalid supplier price outright (ADR-025's
 * floor) and goes by direction (ADR-138): a rise applies at once, and a
 * big drop is held at the old cost, still selling, until it has stayed
 * low long enough. Many big swings at once are treated as bad data.
 */
final class PackagePriceSyncService
{
    public function __construct(
        private readonly PackageMarkupService $markup,
        private readonly ComboPricingService $comboPricing,
        private readonly PackageCostService $packageCost,
        private readonly SupplierPackageStatusService $packageStatus,
        private readonly PackageActivation $activation,
        private readonly BackupFailureAlerter $alerter,
    ) {}

    public function apply(Supplier $supplier, Carbon $syncedAt, ?int $priceSyncRunId): PackagePriceSyncResult
    {
        $packages = Package::query()->where('supplier_id', $supplier->id)->get();

        $supplierProducts = SupplierProduct::query()
            ->where('supplier_id', $supplier->id)
            ->whereIn('external_ref', $packages->pluck('supplier_package_ref'))
            ->get()
            ->keyBy('external_ref');

        $policy = PriceSwingPolicy::for($supplier);
        $openRows = PendingPriceChange::query()->where('status', 'pending')->whereIn('package_id', $packages->pluck('id'))->pluck('id', 'package_id');
        $massChange = $this->isMassChange($supplier, $packages, $supplierProducts, $syncedAt, $policy);

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

            $held = $openRows->has($package->id);

            if (! $seenThisRun) {
                if ($held) {
                    $this->closeUnseenRow($package);
                    $affectedGameIds[] = $package->game_id;
                }

                if ($this->activation->deactivate($package, PackageActivation::SUPPLIER_SYNC, $priceSyncRunId)) {
                    $deactivated++;
                    $affectedGameIds[] = $package->game_id;
                }

                continue;
            }

            // ADR-138 decision 11: an open row is checked even at an unchanged
            // price, the usual end of a false drop.
            if ($product->price_sen !== null && ($held || $product->price_sen !== $package->cost_price)) {
                $outcome = $this->evaluatePriceChange($package, $product->price_sen, $priceSyncRunId, $policy, $massChange, $product->status_raw === 'active', $held);

                match ($outcome) {
                    'applied' => $priceChanged++,
                    'anomaly_flagged' => $anomaliesFlagged++,
                    'floor_rejected' => $floorRejected++,
                    'skipped' => null,
                };

                if ($held || $outcome === 'applied' || $outcome === 'anomaly_flagged') {
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

            if ($product->status_raw !== 'active' && $this->activation->deactivate($package, PackageActivation::SUPPLIER_SYNC, $priceSyncRunId)) {
                $deactivated++;
                $affectedGameIds[] = $package->game_id;
            }
        }

        // ADR-134 decision 4: this run priced the supplier from a deposit, so the packages stopped for having none sell again — now, at the cost just applied, and only those the supplier still lists as active.
        $sellable = $supplierProducts->filter(fn (SupplierProduct $p) => $p->status_raw === 'active' && $p->last_synced_at?->timestamp === $syncedAt->timestamp)->keys()->all();
        $affectedGameIds = [...$affectedGameIds, ...$this->reactivateUnfunded($supplier, $sellable)];

        return new PackagePriceSyncResult(
            priceChanged: $priceChanged,
            deactivated: $deactivated,
            affectedGameIds: array_values(array_unique($affectedGameIds)),
            floorRejected: $floorRejected,
            anomaliesFlagged: $anomaliesFlagged,
        );
    }

    /**
     * @param  list<string>  $refs
     * @return list<int> the games whose packages came back
     */
    private function reactivateUnfunded(Supplier $supplier, array $refs): array
    {
        $gameIds = Package::query()->where('supplier_id', $supplier->id)->where('is_active', false)
            ->where('deactivated_reason', SupplierPackageStatusService::UNFUNDED)->whereIn('supplier_package_ref', $refs)
            ->distinct()->pluck('game_id')->all();

        if ($gameIds !== []) {
            $this->packageStatus->reactivate($supplier, null, code: SupplierPackageStatusService::UNFUNDED, refs: $refs);
        }

        return $gameIds;
    }

    /**
     * An admin applies a held price now. Null when the row is no longer open.
     */
    public function approveHeld(PendingPriceChange $change, ?int $adminUserId): ?Package
    {
        $package = DB::transaction(function () use ($change, $adminUserId) {
            $locked = Package::query()->lockForUpdate()->findOrFail($change->package_id);
            $row = PendingPriceChange::query()->lockForUpdate()->whereKey($change->id)->where('status', 'pending')->first();

            if ($row === null) {
                return null;
            }

            $this->applyHeld($locked, $row, 'approved', $row->price_sync_run_id, $adminUserId);

            return $locked;
        });

        if ($package !== null) {
            $this->comboPricing->recomputeForComponentChange($package, $change->price_sync_run_id);
        }

        return $package;
    }

    /**
     * ADR-138 decision 13: every price of a supplier comes from one deposit
     * average, so many big swings in one run mean a bad input, not a market.
     *
     * @param  Collection<int, Package>  $packages
     * @param  Collection<string, SupplierProduct>  $products
     */
    private function isMassChange(Supplier $supplier, Collection $packages, Collection $products, Carbon $syncedAt, PriceSwingPolicy $policy): bool
    {
        $swinging = $packages->filter(function (Package $package) use ($products, $syncedAt, $policy) {
            $product = $products->get($package->supplier_package_ref);

            return $product?->price_sen > 0
                && $product->last_synced_at?->timestamp === $syncedAt->timestamp
                && $policy->isSwing($package->cost_price, $product->price_sen);
        })->count();

        if ($swinging <= $policy->massLimit) {
            return false;
        }

        $day = CarbonImmutable::now(MalaysiaTime::TIMEZONE)->toDateString();

        if (Cache::add("price-sync:mass-swing-alert:{$supplier->id}:{$day}", true, now()->addDay())) {
            $this->alerter->alert(
                "{$supplier->name} prices moved together",
                "{$swinging} packages moved {$policy->holdPercent}% or more in one Price Sync, so this is treated as bad data. "
                .'They keep selling at the old price and nothing applies by itself. Check the latest deposit. '
                .'If the change is real, use Approve all in Price Sync Center.',
                'Price Sync',
            );
        }

        return true;
    }

    /**
     * ADR-025 decision 1: a price at or below zero is rejected outright.
     * ADR-138 decisions 7–13 for the rest. A package with no prior cost has
     * no baseline to compare with, so it applies directly.
     *
     * @return 'applied'|'floor_rejected'|'anomaly_flagged'|'skipped'
     */
    private function evaluatePriceChange(Package $package, int $newCostPrice, ?int $priceSyncRunId, PriceSwingPolicy $policy, bool $massChange, bool $productActive, bool $held): string
    {
        if ($newCostPrice <= 0) {
            Log::error('Price Sync: rejected a non-positive supplier price', [
                'package_id' => $package->id,
                'price_sen' => $newCostPrice,
            ]);

            return 'floor_rejected';
        }

        if (! $held && ! $policy->isSwing($package->cost_price, $newCostPrice)) {
            $this->packageCost->apply($package, $newCostPrice, $priceSyncRunId);

            return 'applied';
        }

        // The package row serializes this with an admin's Approve; both read
        // it fresh, because `$package` was loaded when the run started.
        return DB::transaction(function () use ($package, $newCostPrice, $priceSyncRunId, $policy, $massChange, $productActive) {
            $locked = Package::query()->lockForUpdate()->findOrFail($package->id);
            $row = PendingPriceChange::query()->where('package_id', $locked->id)->where('status', 'pending')->first();
            $cost = $locked->cost_price;
            $isDrop = $newCostPrice < $cost;
            $hold = $policy->isSwing($cost, $newCostPrice) && ($massChange || $isDrop);

            if (! $hold) {
                if ($newCostPrice !== $cost) {
                    $this->packageCost->apply($locked, $newCostPrice, $priceSyncRunId);
                }

                if ($row !== null) {
                    $row->update(['status' => 'superseded']);
                }

                return $newCostPrice === $cost ? 'skipped' : 'applied';
            }

            if (! $productActive) {
                if ($row !== null) {
                    $row->update(['status' => 'superseded']);
                }

                return 'skipped';
            }

            if ($row === null) {
                $this->openRow($locked, $newCostPrice, $priceSyncRunId);

                return 'anomaly_flagged';
            }

            if (! $massChange && $isDrop && $row->created_at->lte(now()->subHours($policy->holdHours))) {
                $this->applyHeld($locked, $row, 'auto_applied', $priceSyncRunId, null);

                return 'applied';
            }

            // Decision 9: "stayed at or below X" is what a hold proves, so a
            // held drop only moves up. A held rise follows the latest price.
            $proposal = $isDrop && $row->proposed_cost_price < $cost ? max($row->proposed_cost_price, $newCostPrice) : $newCostPrice;
            $row->update([
                'proposed_cost_price' => $proposal,
                'proposed_standard_selling_price' => $this->markup->calculateStandardSellingPrice($proposal, (float) $locked->markup_percent),
            ]);

            return 'skipped';
        });
    }

    private function openRow(Package $package, int $proposedCostPrice, ?int $priceSyncRunId): void
    {
        PendingPriceChange::query()->create([
            'price_sync_run_id' => $priceSyncRunId,
            'package_id' => $package->id,
            'old_cost_price' => $package->cost_price,
            'proposed_cost_price' => $proposedCostPrice,
            'old_standard_selling_price' => $package->standard_selling_price,
            'proposed_standard_selling_price' => $this->markup->calculateStandardSellingPrice($proposedCostPrice, (float) $package->markup_percent),
        ]);

        Log::warning('Price Sync: held a price swing', [
            'package_id' => $package->id,
            'old_cost_price' => $package->cost_price,
            'proposed_cost_price' => $proposedCostPrice,
        ]);
    }

    private function applyHeld(Package $locked, PendingPriceChange $row, string $status, ?int $priceSyncRunId, ?int $adminUserId): void
    {
        $this->packageCost->apply($locked, $row->proposed_cost_price, $priceSyncRunId, $adminUserId);
        $row->update(['status' => $status]);
    }

    /** Decision 11: a product this run didn't see breaks the hold, so the clock restarts. */
    private function closeUnseenRow(Package $package): void
    {
        DB::transaction(function () use ($package) {
            $locked = Package::query()->lockForUpdate()->findOrFail($package->id);
            $row = PendingPriceChange::query()->where('package_id', $locked->id)->where('status', 'pending')->first();

            if ($row !== null) {
                $row->update(['status' => 'superseded']);
            }
        });
    }
}
