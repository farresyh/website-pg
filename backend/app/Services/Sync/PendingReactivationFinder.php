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
 *
 * A `supplier_products` row's `status_raw` is only ever touched by a
 * sync run that actually saw that item — a package deactivated for
 * *vanishing* from the catalog (`! $seenThisRun` in
 * PackagePriceSyncService::apply()) leaves its own `supplier_products`
 * row completely untouched, so that row's `status_raw` is still
 * whatever it was *before* the deactivation (almost always 'active',
 * since the item was sellable up to that point) — stale evidence that
 * predates the very deactivation it would otherwise seem to explain
 * away. `last_synced_at > deactivated_at` requires the 'active' signal
 * to come from a sync that ran *after* the package went offline, not
 * leftover state from before — found live 2026-08-21 while verifying
 * ADR-025 against a real Gamevion sync, confirmed structural (not a
 * side-effect of a long gap between syncs) via a short-gap repro in
 * PendingReactivationFinderTest.
 */
final class PendingReactivationFinder
{
    /**
     * @return Collection<int, Package>
     */
    public function find(?int $supplierId = null): Collection
    {
        $packages = Package::query()
            ->with('game', 'supplier')
            ->where('is_active', false)
            ->where('deactivated_reason', 'supplier_sync')
            ->when($supplierId !== null, fn ($query) => $query->where('supplier_id', $supplierId))
            ->get();

        $confirmedActiveSince = SupplierProduct::query()
            // ADR-100 — scoped by supplier whenever the caller gives
            // one (PendingReactivationAutoApprover always does), since
            // `external_ref` is only unique *per supplier*
            // (`supplier_products`' own unique key is
            // `[supplier_id, external_ref]`) — left unscoped for the
            // default/no-argument call to keep every existing caller's
            // behavior byte-for-byte unchanged.
            ->when($supplierId !== null, fn ($query) => $query->where('supplier_id', $supplierId))
            ->whereIn('external_ref', $packages->pluck('supplier_package_ref'))
            ->where('status_raw', 'active')
            ->get(['external_ref', 'last_synced_at'])
            ->keyBy('external_ref');

        return $packages->filter(function (Package $package) use ($confirmedActiveSince) {
            $product = $confirmedActiveSince->get($package->supplier_package_ref);

            return $product && $package->deactivated_at !== null
                && $product->last_synced_at?->greaterThan($package->deactivated_at);
        });
    }
}
