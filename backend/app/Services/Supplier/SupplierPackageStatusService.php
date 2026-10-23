<?php

namespace App\Services\Supplier;

use App\Http\Controllers\GameController;
use App\Models\DeactivationLog;
use App\Models\Package;
use App\Models\Supplier;
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
 */
final class SupplierPackageStatusService
{
    public const REASON = 'supplier_issue';

    public function __construct(private readonly ComboPricingService $comboPricing) {}

    /** @return array{updated: int, updated_combos: int} */
    public function deactivate(Supplier $supplier, int $adminUserId, ?string $reason, ?int $gameId = null): array
    {
        $this->assertNotPool($supplier);
        $query = $this->packages($supplier, $gameId)->where('is_active', true);
        $packageIds = (clone $query)->pluck('id');
        $gameIds = (clone $query)->distinct()->pluck('game_id')->all();
        $affectedComboCount = 0;

        DB::transaction(function () use ($query, $packageIds, $adminUserId, $reason, &$affectedComboCount) {
            $query->update(['is_active' => false, 'deactivated_reason' => self::REASON, 'deactivated_at' => now()]);

            $now = now();
            $rows = $packageIds->map(fn (int $packageId) => [
                'package_id' => $packageId,
                'admin_user_id' => $adminUserId,
                'reason' => $reason,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            if ($rows !== []) {
                DeactivationLog::query()->insert($rows);
            }

            // Addendum (2026-09-24) — this bulk toggle used to bypass combo
            // cascade entirely (a raw mass update), leaving a combo listed
            // active while one of its legs went dark. A combo's own game is
            // always one of $gameIds (its components share its game).
            $affectedComboCount = $this->comboPricing->cascadeDeactivateForComponents(
                $packageIds,
                priceSyncRunId: null,
                adminUserId: $adminUserId,
                reason: $reason,
            )->count();
        });

        $this->forgetCaches($gameIds);

        return ['updated' => $packageIds->count(), 'updated_combos' => $affectedComboCount];
    }

    /** @return array{updated: int, updated_combos: int} */
    public function reactivate(Supplier $supplier, int $adminUserId, ?int $gameId = null): array
    {
        $this->assertNotPool($supplier);
        $query = $this->packages($supplier, $gameId)->where('is_active', false)->where('deactivated_reason', self::REASON);
        $packageIds = (clone $query)->pluck('id');
        $gameIds = (clone $query)->distinct()->pluck('game_id')->all();
        $affectedComboCount = 0;

        DB::transaction(function () use ($query, $packageIds, $adminUserId, &$affectedComboCount) {
            $query->update(['is_active' => true, 'deactivated_reason' => null, 'deactivated_at' => null]);

            // Addendum (2026-09-24) — a combo cascade-deactivated because one
            // of these components went down can now complete, once every
            // other component is active too.
            $affectedComboCount = $this->comboPricing->cascadeReactivateForComponents(
                $packageIds,
                priceSyncRunId: null,
                adminUserId: $adminUserId,
            )->count();
        });

        $this->forgetCaches($gameIds);

        return ['updated' => $packageIds->count(), 'updated_combos' => $affectedComboCount];
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
