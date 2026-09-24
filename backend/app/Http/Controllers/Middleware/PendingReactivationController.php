<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Http\Controllers\GameController;
use App\Http\Requests\Middleware\BulkPendingReactivationRequest;
use App\Models\Package;
use App\Services\Pricing\ComboPricingService;
use App\Services\Sync\PendingReactivationFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SYNC-5/6, ADR-015 decision #3: a package Deactivation Detection
 * turned off (`deactivated_reason === 'supplier_sync'`) whose
 * supplier item reports active again — never auto-reactivated,
 * surfaced here for an admin to Approve or Dismiss instead. The
 * finding query itself lives in PendingReactivationFinder, shared with
 * PriceSyncController::stats()'s count.
 */
class PendingReactivationController extends Controller
{
    public function __construct(
        private readonly PendingReactivationFinder $finder,
        private readonly ComboPricingService $comboPricing,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->finder->find()->values());
    }

    public function approve(Request $request, Package $package): JsonResponse
    {
        $this->assertPending($package);

        $package->update(['is_active' => true, 'deactivated_reason' => null, 'deactivated_at' => null]);
        $this->comboPricing->cascadeReactivate($package, priceSyncRunId: null, adminUserId: $request->user()?->id);
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
        $pendingIds = $this->finder->find()->pluck('id')->all();

        Package::query()
            ->whereIn('id', array_intersect($request->validated('package_ids'), $pendingIds))
            ->get()
            ->each(function (Package $package) use ($request) {
                $package->update(['is_active' => true, 'deactivated_reason' => null, 'deactivated_at' => null]);
                $this->comboPricing->cascadeReactivate($package, priceSyncRunId: null, adminUserId: $request->user()?->id);
                $this->forgetCaches($package);
            });

        return response()->json(['processed' => count($pendingIds)]);
    }

    public function bulkDismiss(BulkPendingReactivationRequest $request): JsonResponse
    {
        $pendingIds = $this->finder->find()->pluck('id')->all();
        $targetIds = array_intersect($request->validated('package_ids'), $pendingIds);

        Package::query()->whereIn('id', $targetIds)->update(['deactivated_reason' => 'admin']);

        return response()->json(['processed' => count($targetIds)]);
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
