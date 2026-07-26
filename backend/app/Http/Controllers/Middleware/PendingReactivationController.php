<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Http\Controllers\GameController;
use App\Http\Requests\Middleware\BulkPendingReactivationRequest;
use App\Models\Package;
use App\Models\SupplierProduct;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * SYNC-5/6, ADR-015 decision #3: a package Deactivation Detection
 * turned off (`deactivated_reason === 'supplier_sync'`) whose
 * supplier item reports active again — never auto-reactivated,
 * surfaced here for an admin to Approve or Dismiss instead. Computed
 * live against the current `supplier_products` snapshot on every
 * request, the same pattern GameController::packages() already uses
 * for its read-only `supplier_active` indicator, not a stored flag
 * `SyncSupplierPricesJob` would have to keep in sync separately.
 */
class PendingReactivationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json($this->pendingReactivations()->values());
    }

    public function approve(Package $package): JsonResponse
    {
        $this->assertPending($package);

        $package->update(['is_active' => true, 'deactivated_reason' => null, 'deactivated_at' => null]);
        $this->forgetCaches($package);

        return response()->json($package);
    }

    public function dismiss(Package $package): JsonResponse
    {
        $this->assertPending($package);

        // Converts it into an admin decision (ADR-015 decision #3) —
        // stays inactive, but future supplier flips stop being
        // monitored for reactivation on this package.
        $package->update(['deactivated_reason' => 'admin']);

        return response()->json($package);
    }

    public function bulkApprove(BulkPendingReactivationRequest $request): JsonResponse
    {
        $pendingIds = $this->pendingReactivations()->pluck('id')->all();

        Package::query()
            ->whereIn('id', array_intersect($request->validated('package_ids'), $pendingIds))
            ->get()
            ->each(function (Package $package) {
                $package->update(['is_active' => true, 'deactivated_reason' => null, 'deactivated_at' => null]);
                $this->forgetCaches($package);
            });

        return response()->json(['processed' => count($pendingIds)]);
    }

    public function bulkDismiss(BulkPendingReactivationRequest $request): JsonResponse
    {
        $pendingIds = $this->pendingReactivations()->pluck('id')->all();
        $targetIds = array_intersect($request->validated('package_ids'), $pendingIds);

        Package::query()->whereIn('id', $targetIds)->update(['deactivated_reason' => 'admin']);

        return response()->json(['processed' => count($targetIds)]);
    }

    /**
     * @return Collection<int, Package>
     */
    private function pendingReactivations(): Collection
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

    private function assertPending(Package $package): void
    {
        abort_unless($package->deactivated_reason === 'supplier_sync', 422, 'This package is not pending reactivation.');
    }

    private function forgetCaches(Package $package): void
    {
        GameController::forgetPackagesCache($package->game_id);
        GameController::forgetIndexCache();
    }
}
