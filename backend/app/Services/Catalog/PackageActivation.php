<?php

namespace App\Services\Catalog;

use App\Models\DeactivationLog;
use App\Models\Package;
use App\Models\SupplierProduct;
use App\Services\Currency\LandedCostRate;
use App\Services\Supplier\SupplierPackageStatusService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ADR-015 2026-11-05 addendum: the only code that switches a package on.
 * Checkout sells any package with `is_active`, and several reasons to keep
 * one off can hold at once while the package stores a single reason. So the
 * blockers are computed here when asked. Each maps to the stored reason whose
 * own path brings the package back later (the supplier's Reactivate, the unfunded sync, Pending Reactivation, the combo
 * cascade).
 *
 * ADR-138: also the only code that switches a package off. The reason, the
 * log and the combo cascade are written together in one transaction, so a
 * combo can't stay on sale with an off component. Callers forget caches: a
 * combo shares its components' game, and sync batches per run.
 */
final class PackageActivation
{
    public const SUPPLIER_SYNC = 'supplier_sync';

    public const COMBO_COMPONENT = 'combo_component_deactivated';

    public const ADMIN = 'admin';

    private const EXPLANATIONS = [
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

    /**
     * ADR-138 decision 16: a package created off goes on sale here, or stays off
     * with a reason that has its own way back. The caller passes `is_active =>
     * false` explicitly: the default lives in the database, and activate()
     * returns early on anything already on.
     *
     * @return ?string why it stayed off, for the admin; null when on sale
     */
    public function activateNew(Package $package): ?string
    {
        return $this->activate($package, null) ? null : self::explain($package->deactivated_reason);
    }

    /**
     * Switches one package off under $code, and its combos with it. A package
     * already off keeps its reason.
     *
     * @return bool whether this call switched it off
     */
    public function deactivate(Package $package, string $code, ?int $priceSyncRunId = null, ?int $adminUserId = null): bool
    {
        if (! $package->is_active) {
            return false;
        }

        DB::transaction(fn () => $this->switchOffWithCombos(collect([$package->id]), $code, $priceSyncRunId, $adminUserId, null));
        $package->refresh();

        return true;
    }

    /**
     * The bulk form: stays a few statements however many rows (Digiflazz has
     * over 1,000 active packages, ADR-046 2026-09-24 addendum).
     *
     * @param  Collection<int, int>  $packageIds
     * @param  ?string  $note  the admin's text, stored on each log row
     * @return int how many combos went off with them
     */
    public function deactivateMany(Collection $packageIds, string $code, ?int $adminUserId, ?string $note): int
    {
        return DB::transaction(function () use ($packageIds, $code, $adminUserId, $note) {
            $active = Package::query()->whereIn('id', $packageIds)->where('is_active', true)->pluck('id');

            return $this->switchOffWithCombos($active, $code, null, $adminUserId, $note);
        });
    }

    public function blocker(Package $package): ?string
    {
        if ($package->is_combo) {
            return $package->components()->where('packages.is_active', false)->exists() ? self::COMBO_COMPONENT : null;
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

    /**
     * @param  Collection<int, int>  $packageIds  rows known to be active
     * @return int how many combos went off with them
     */
    private function switchOffWithCombos(Collection $packageIds, string $code, ?int $priceSyncRunId, ?int $adminUserId, ?string $note): int
    {
        $this->switchOff($packageIds, $code, $priceSyncRunId, $adminUserId, $note);

        // The pivot, not whereHas('components'): that self-joins `packages`,
        // and a filter on `packages.id` then matches the outer query's row.
        $comboIds = Package::query()
            ->whereIn('id', DB::table('package_components')->whereIn('component_package_id', $packageIds)->select('combo_package_id'))
            ->where('is_combo', true)
            ->where('is_active', true)
            ->pluck('id');

        $this->switchOff($comboIds, self::COMBO_COMPONENT, $priceSyncRunId, $adminUserId, $note);

        return $comboIds->count();
    }

    /** @param  Collection<int, int>  $packageIds */
    private function switchOff(Collection $packageIds, string $code, ?int $priceSyncRunId, ?int $adminUserId, ?string $note): void
    {
        if ($packageIds->isEmpty()) {
            return;
        }

        $now = now();

        Package::query()->whereIn('id', $packageIds)->update(['is_active' => false, 'deactivated_reason' => $code, 'deactivated_at' => $now]);

        DeactivationLog::query()->insert($packageIds->map(fn (int $packageId) => [
            'package_id' => $packageId,
            'price_sync_run_id' => $priceSyncRunId,
            'admin_user_id' => $adminUserId,
            'reason' => $note,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
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
