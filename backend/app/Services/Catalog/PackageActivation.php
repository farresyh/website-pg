<?php

namespace App\Services\Catalog;

use App\Models\Package;
use App\Models\PendingPriceChange;
use App\Models\SupplierProduct;
use App\Services\Currency\LandedCostRate;
use App\Services\Supplier\SupplierPackageStatusService;
use Illuminate\Support\Carbon;

/**
 * ADR-015 2026-11-05 addendum: the only code that switches a package on.
 * Checkout sells any package with `is_active`, and several reasons to keep
 * one off can hold at once while the package stores a single reason. So the
 * blockers are computed here when asked. Each maps to the stored reason whose
 * own path brings the package back later (Pending Price Changes, the
 * supplier's Reactivate, the unfunded sync, Pending Reactivation, the combo
 * cascade).
 */
final class PackageActivation
{
    public const PRICE_ANOMALY = 'price_anomaly';

    public const SUPPLIER_SYNC = 'supplier_sync';

    public const COMBO_COMPONENT = 'combo_component_deactivated';

    public const ADMIN = 'admin';

    private const EXPLANATIONS = [
        self::PRICE_ANOMALY => 'a supplier price change is waiting for review in the Price Sync Center',
        SupplierPackageStatusService::REASON => 'its supplier is switched off',
        SupplierPackageStatusService::UNFUNDED => 'its supplier has no recorded deposit',
        self::SUPPLIER_SYNC => 'its supplier does not list it as available',
        self::COMBO_COMPONENT => 'one of its components is inactive',
    ];

    public function __construct(private readonly LandedCostRate $landedCost) {}

    /**
     * Switches the package on unless a blocker holds; then it stays off,
     * carrying that blocker as its reason.
     *
     * @param  ?string  $clears  the stored reason this caller resolves; null for an
     *                           admin's own switch-on, which may clear any reason
     * @return bool whether the package is on afterwards
     */
    public function activate(Package $package, ?string $clears): bool
    {
        if ($package->is_active) {
            return true;
        }

        if ($clears !== null && $package->deactivated_reason !== $clears) {
            return false;
        }

        $blocker = $this->blocker($package);

        if ($blocker !== null) {
            if ($package->deactivated_reason !== $blocker) {
                $package->update(['deactivated_reason' => $blocker, 'deactivated_at' => now()]);
            }

            return false;
        }

        $package->update(['is_active' => true, 'deactivated_reason' => null, 'deactivated_at' => null]);

        return true;
    }

    public function blocker(Package $package): ?string
    {
        if ($package->is_combo) {
            return $package->components()->where('packages.is_active', false)->exists() ? self::COMBO_COMPONENT : null;
        }

        if (PendingPriceChange::query()->where('package_id', $package->id)->where('status', 'pending')->exists()) {
            return self::PRICE_ANOMALY;
        }

        $supplier = $package->supplier;

        return match (true) {
            $supplier === null => null,
            ! $supplier->is_active => SupplierPackageStatusService::REASON,
            ! $this->landedCost->isFunded($supplier) => SupplierPackageStatusService::UNFUNDED,
            $supplier->isSyncable() && ! $this->listedBySupplier($package) => self::SUPPLIER_SYNC,
            default => null,
        };
    }

    /** Why a package that stayed off is still off, for an admin's error message. */
    public static function explain(?string $reason): string
    {
        return 'This package stays off: '.(self::EXPLANATIONS[$reason] ?? 'it was switched off for another reason').'.';
    }

    /** Active at the supplier and seen by its latest successful catalog sync (rows a sync saw share its timestamp). */
    private function listedBySupplier(Package $package): bool
    {
        $products = SupplierProduct::query()->where('supplier_id', $package->supplier_id);
        $product = (clone $products)->where('external_ref', $package->supplier_package_ref)->first(['status_raw', 'last_synced_at']);

        if ($product === null || $product->status_raw !== 'active' || $product->last_synced_at === null) {
            return false;
        }

        return $product->last_synced_at->gte(Carbon::parse($products->max('last_synced_at')));
    }
}
