<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Http\Controllers\GameController;
use App\Models\Package;
use App\Services\Pricing\ComboPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-016 decision #3: "Manually Dismissed Packages" — packages an
 * admin explicitly took out of Pending Reactivation monitoring
 * (`deactivated_reason === 'admin'`), browsable in one place instead
 * of hunting through /admin/games individually. Restoring one here is
 * the same effect as PendingReactivationController::approve() (turn
 * back on, clear the deactivation fields) but doesn't require the
 * package's supplier item to currently be active — an admin can
 * restore a dismissed package on their own judgement regardless of
 * live supplier status.
 */
class DismissedPackageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);

        $query = Package::query()
            ->with('game', 'supplier')
            ->where('deactivated_reason', 'admin')
            ->orderBy('deactivated_at', 'desc');

        return response()->json($query->paginate($perPage)->withQueryString());
    }

    public function restore(Request $request, Package $package, ComboPricingService $comboPricing): JsonResponse
    {
        abort_unless($package->deactivated_reason === 'admin', 422, 'This package was not manually dismissed.');

        $package->update(['is_active' => true, 'deactivated_reason' => null, 'deactivated_at' => null]);
        $comboPricing->cascadeReactivate($package, priceSyncRunId: null, adminUserId: $request->user()?->id);

        GameController::forgetPackagesCache($package->game_id);
        GameController::forgetIndexCache();

        return response()->json($package);
    }
}
