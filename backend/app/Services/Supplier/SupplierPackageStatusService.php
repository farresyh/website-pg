<?php

namespace App\Services\Supplier;

use App\Http\Controllers\GameController;
use App\Models\Package;
use App\Models\PendingPriceChange;
use App\Models\Supplier;
use App\Services\Catalog\PackageActivation;
use App\Services\Pricing\ComboPricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-046 decisions 9/10: switch all of one supplier's packages (or one
 * game's) off or back on, for "Deactivate All / by Game" and its paired
 * "Reactivate", and since ADR-125 decision 64 for a manual supplier's own
 * active switch. Deactivating needs no pricing/routing logic: ADR-034's
 * storefront dedup already filters to is_active packages before picking the
 * cheapest match, so a healthy supplier's equivalent package takes over.
 *
 * Reactivate is scoped to deactivated_reason = 'supplier_issue' only, never
 * a blanket false→true, since that could also re-activate a package Price
 * Sync deactivated for an unrelated reason (supplier_sync/price_anomaly),
 * bypassing ADR-025's swing-review safety net.
 *
 * ADR-134 decision 4: Price Sync uses the same pair, under its own reason
 * `unfunded`, to stop selling a foreign-currency supplier with no deposit
 * and to bring back exactly those packages once one is recorded.
 */
final class SupplierPackageStatusService
{
    public const REASON = 'supplier_issue';

    public const UNFUNDED = 'unfunded';

    public function __construct(
        private readonly ComboPricingService $comboPricing,
        private readonly PackageActivation $activation,
    ) {}

    /**
     * ADR-138 decision 20: an API supplier that is off has no package on sale.
     * Stays outside a transaction: the flag has to be committed before any
     * package goes off, so a parallel activate() already sees the blocker.
     * Sync skips an off supplier, so nothing else would close its open rows.
     */
    public function switchOffApiSupplier(Supplier $supplier, int $adminUserId): void
    {
        $supplier->update(['is_active' => false]);

        PendingPriceChange::query()
            ->whereIn('package_id', $this->packages($supplier, null)->select('id'))
            ->where('status', 'pending')
            ->update(['status' => 'superseded']);

        $this->deactivate($supplier, $adminUserId, 'Supplier switched off');
    }

    /** @return array{updated: int, updated_combos: int} */
    public function deactivate(Supplier $supplier, ?int $adminUserId, ?string $reason, ?int $gameId = null, string $code = self::REASON): array
    {
        $this->assertNotPool($supplier);
        $query = $this->packages($supplier, $gameId)->where('is_active', true);
        $packageIds = (clone $query)->pluck('id');
        $gameIds = (clone $query)->distinct()->pluck('game_id')->all();
        $affectedComboCount = $this->activation->deactivateMany($packageIds, $code, $adminUserId, $reason);

        $this->forgetCaches($gameIds);

        return ['updated' => $packageIds->count(), 'updated_combos' => $affectedComboCount];
    }

    /**
     * ADR-015 2026-11-05 addendum: each package goes through PackageActivation,
     * so one still blocked for another reason (an open price swing, a product
     * the supplier no longer lists) stays off and is counted as skipped.
     *
     * @param  ?list<string>  $refs  only these supplier refs (Price Sync passes what its run saw active)
     * @return array{updated: int, updated_combos: int, skipped: int}
     */
    public function reactivate(Supplier $supplier, ?int $adminUserId, ?int $gameId = null, string $code = self::REASON, ?array $refs = null): array
    {
        $this->assertNotPool($supplier);
        $packages = $this->packages($supplier, $gameId)->where('is_active', false)->where('deactivated_reason', $code)
            ->when($refs !== null, fn ($q) => $q->whereIn('supplier_package_ref', $refs))
            ->with('supplier')
            ->get();
        $activatedIds = collect();
        $affectedComboCount = 0;

        DB::transaction(function () use ($packages, $code, $adminUserId, $activatedIds, &$affectedComboCount) {
            foreach ($packages as $package) {
                if ($this->activation->activate($package, $code)) {
                    $activatedIds->push($package->id);
                }
            }

            // Addendum (2026-09-24) — a combo cascade-deactivated because one
            // of these components went down can now complete, once every
            // other component is active too.
            $affectedComboCount = $this->comboPricing->cascadeReactivateForComponents(
                $activatedIds,
                priceSyncRunId: null,
                adminUserId: $adminUserId,
            )->count();
        });

        $this->forgetCaches($packages->pluck('game_id')->unique()->values()->all());

        return ['updated' => $activatedIds->count(), 'updated_combos' => $affectedComboCount, 'skipped' => $packages->count() - $activatedIds->count()];
    }

    private function packages(Supplier $supplier, ?int $gameId)
    {
        return Package::query()
            ->where('supplier_id', $supplier->id)
            ->when($gameId !== null, fn ($q) => $q->where('game_id', $gameId));
    }

    /** @param  list<int>  $gameIds */
    private function forgetCaches(array $gameIds): void
    {
        foreach ($gameIds as $gameId) {
            GameController::forgetPackagesCache($gameId, false);
        }
        if ($gameIds !== []) {
            GameController::forgetIndexCache();
        }
    }

    /** ADR-126 F1/F5: the pool's packages are joki's, switched with Joki itself. */
    private function assertNotPool(Supplier $supplier): void
    {
        if ($supplier->isAssignmentPool()) {
            throw ValidationException::withMessages(['supplier' => ['The joki pool\'s packages are managed in Joki settings.']]);
        }
    }
}
