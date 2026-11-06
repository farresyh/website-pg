<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Http\Controllers\GameController;
use App\Models\Package;
use App\Services\Catalog\PackageActivation;
use App\Services\Pricing\ComboPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-016 decision #3: "Manually Dismissed Packages" — packages an
 * admin explicitly took out of Pending Reactivation monitoring
 * (`deactivated_reason === 'admin'`), browsable in one place instead
 * of hunting through /admin/games individually. An admin's own
 * switch-off lands here too (ADR-138 decision 3). Restoring goes through
 * PackageActivation like every switch-on, so a blocker refuses it: the
 * package then carries the blocker's reason and leaves this list.
 */
class DismissedPackageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);

        $query = Package::query()
            ->with('game', 'supplier')
            ->where('deactivated_reason', 'admin')
            ->where('is_active', false)
            ->orderBy('deactivated_at', 'desc')
            ->orderByDesc('id'); // pages overlap on a shared time without it

        return response()->json($query->paginate($perPage)->withQueryString());
    }

    public function restore(Request $request, Package $package, ComboPricingService $comboPricing, PackageActivation $activation): JsonResponse
    {
        abort_unless($package->deactivated_reason === PackageActivation::ADMIN, 422, 'This package was not manually dismissed.');

        if (! $activation->activate($package, PackageActivation::ADMIN)) {
            abort(422, PackageActivation::explain($package->deactivated_reason));
        }
        $comboPricing->cascadeReactivate($package, priceSyncRunId: null, adminUserId: $request->user()?->id);

        GameController::forgetPackagesCache($package->game_id);
        GameController::forgetIndexCache();

        return response()->json($package);
    }
}
