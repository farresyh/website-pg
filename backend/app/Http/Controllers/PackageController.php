<?php

namespace App\Http\Controllers;

use App\Http\Requests\Games\UpdatePackageDenominationRequest;
use App\Http\Requests\Games\UpdatePackageMarkupRequest;
use App\Http\Requests\Games\UpdatePackageRequest;
use App\Http\Requests\Games\UpdatePackageStatusRequest;
use App\Models\Package;
use App\Services\Pricing\PackageMarkupService;
use Illuminate\Http\JsonResponse;

/**
 * GAME-7/GAME-4: admin edits or removes an already-promoted Package.
 * Creation happens only via SupplierProductController::promote() —
 * there's no direct "create a Package from scratch" endpoint,
 * deliberately, since every Package must trace back to a real
 * supplier item (`supplier_package_ref`).
 */
class PackageController extends Controller
{
    public function update(UpdatePackageRequest $request, Package $package): JsonResponse
    {
        $package->update($request->validated());
        GameController::forgetPackagesCache($package->game_id);

        return response()->json($package);
    }

    /**
     * Founder revision, 2026-07-25: recomputes and stores
     * `reseller_cost_price` from the new markup — matches the legacy
     * reference system's inline "Markup % + Update" row pattern
     * (legacy-reference-notes.md), not a full edit form.
     */
    public function updateMarkup(UpdatePackageMarkupRequest $request, Package $package, PackageMarkupService $markup): JsonResponse
    {
        $markupPercent = (float) $request->validated('markup_percent');

        $package->update([
            'markup_percent' => $markupPercent,
            'reseller_cost_price' => $markup->calculateResellerCostPrice($package->cost_price, $markupPercent),
        ]);
        GameController::forgetPackagesCache($package->game_id);

        return response()->json($package);
    }

    /**
     * Inline on/off toggle, same pattern as AdminUserController's own
     * updateStatus — a dedicated lightweight endpoint rather than
     * requiring the full edit form just to flip one flag.
     */
    public function updateStatus(UpdatePackageStatusRequest $request, Package $package): JsonResponse
    {
        $package->update(['is_active' => $request->validated('is_active')]);
        GameController::forgetPackagesCache($package->game_id);

        return response()->json($package);
    }

    /**
     * ADR-034 decision 3/4: admin-curated, not auto-matched by name —
     * this is the edit-time half (promote-time half is
     * SupplierProductController::promote()), used to backfill the
     * equivalence key onto an already-promoted package (e.g. the
     * existing Gamevion package once a matching Digiflazz one exists).
     */
    public function updateDenomination(UpdatePackageDenominationRequest $request, Package $package): JsonResponse
    {
        $package->update(['denomination' => $request->validated('denomination')]);
        GameController::forgetPackagesCache($package->game_id);

        return response()->json($package);
    }

    public function destroy(Package $package): JsonResponse
    {
        $gameId = $package->game_id;
        $package->delete();
        GameController::forgetIndexCache();
        GameController::forgetPackagesCache($gameId);

        return response()->json(null, 204);
    }
}
