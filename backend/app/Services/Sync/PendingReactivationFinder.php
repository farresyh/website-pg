<?php

namespace App\Services\Sync;

use App\Models\Package;
use App\Models\SupplierProduct;
use Illuminate\Database\Eloquent\Collection;

/**
 * ADR-015 decision #3 / ADR-016 stat card: a package Deactivation
 * Detection turned off (`deactivated_reason === 'supplier_sync'`)
 * whose supplier item reports active again, computed live against the
 * current `supplier_products` snapshot on every call — never a stored
 * flag. Shared between PendingReactivationController (the actual
 * queue) and PriceSyncController::stats() (just the count), so the
 * query lives in exactly one place.
 */
final class PendingReactivationFinder
{
    /**
     * @return Collection<int, Package>
     */
    public function find(): Collection
    {
        $packages = Package::query()
            ->with('game', 'supplier')
            ->where('is_active', false)
            ->where('deactivated_reason', 'supplier_sync')
            ->get();

        $activeAtSupplierRefs = SupplierProduct::query()
            ->whereIn('external_ref', $packages->pluck('supplier_package_ref'))
            ->where('status_raw', 'active')
            ->pluck('external_ref')
            ->all();

        return $packages->filter(
            fn (Package $package) => in_array($package->supplier_package_ref, $activeAtSupplierRefs, true),
        );
    }
}
