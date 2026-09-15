<?php

namespace App\Http\Controllers;

use App\Http\Requests\Games\StoreComboPackageRequest;
use App\Http\Requests\Games\UpdateComboPackageOverrideRequest;
use App\Http\Requests\Games\UpdatePackageCatalogCodeRequest;
use App\Http\Requests\Games\UpdatePackageDenominationRequest;
use App\Http\Requests\Games\UpdatePackageMarkupRequest;
use App\Http\Requests\Games\UpdatePackageRequest;
use App\Http\Requests\Games\UpdatePackageStatusRequest;
use App\Models\Game;
use App\Models\Package;
use App\Services\Pricing\ComboPricingService;
use App\Services\Pricing\PackageMarkupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GAME-7/GAME-4: admin edits or removes an already-promoted Package.
 * Creation happens only via SupplierProductController::promote() —
 * every ordinary Package must trace back to a real supplier item
 * (`supplier_package_ref`). `storeCombo()` (ADR-094) is the one
 * deliberate exception: a combo Package is assembled from several
 * already-promoted Packages instead, so it needs a genuine "create
 * from scratch" entry point.
 */
class PackageController extends Controller
{
    /**
     * ADR-094 decisions 1-4, 18-20: assembles a combo Package from its
     * `components` — no supplier call, no `supplier_id`/
     * `supplier_package_ref` of its own (decision 3). Pricing itself
     * is `ComboPricingService::recompute()`'s job (decision 5/6) — the
     * same computation Price Sync's own per-component-change hook
     * reuses, not duplicated here.
     */
    public function storeCombo(StoreComboPackageRequest $request, Game $game, ComboPricingService $comboPricing): JsonResponse
    {
        $validated = $request->validated();

        $combo = DB::transaction(function () use ($game, $validated, $comboPricing) {
            $combo = Package::query()->create([
                'game_id' => $game->id,
                'name' => $validated['name'],
                'denomination' => 0,
                'cost_price' => 0,
                'standard_selling_price' => 0,
                'markup_percent' => 0,
                'is_active' => true,
                'is_combo' => true,
                'supplier_id' => null,
                'supplier_package_ref' => null,
            ]);

            foreach ($validated['components'] as $index => $component) {
                $combo->components()->attach($component['package_id'], [
                    'quantity' => $component['quantity'],
                    'sort_order' => $index,
                ]);
            }

            $comboPricing->recompute($combo);

            return $combo;
        });

        GameController::forgetPackagesCache($game->id);

        return response()->json($combo->load('components'), 201);
    }

    /**
     * ADR-094 decision 5's second half: an admin deliberately prices a
     * specific combo more aggressively or more profitably than the
     * default sum-of-components price. Setting both fields to null
     * reverts to that default on the next recompute (immediate, here).
     */
    public function updateComboOverride(UpdateComboPackageOverrideRequest $request, Package $package, ComboPricingService $comboPricing): JsonResponse
    {
        $package->update($request->validated());
        $comboPricing->recompute($package);
        GameController::forgetPackagesCache($package->game_id);

        return response()->json($package->load('components'));
    }

    public function update(UpdatePackageRequest $request, Package $package): JsonResponse
    {
        $package->update($request->validated());
        GameController::forgetPackagesCache($package->game_id);

        return response()->json($package);
    }

    /**
     * Founder revision, 2026-07-25: recomputes and stores
     * `standard_selling_price` from the new markup — matches the legacy
     * reference system's inline "Markup % + Update" row pattern
     * (legacy-reference-notes.md), not a full edit form.
     */
    public function updateMarkup(UpdatePackageMarkupRequest $request, Package $package, PackageMarkupService $markup): JsonResponse
    {
        $markupPercent = (float) $request->validated('markup_percent');

        $package->update([
            'markup_percent' => $markupPercent,
            'standard_selling_price' => $markup->calculateStandardSellingPrice($package->cost_price, $markupPercent),
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

    /**
     * ADR-075's catalog-code addendum (2026-09-04), decision 2: the
     * `catalog_code` counterpart to updateDenomination() above — sets
     * the equivalence key for a bundle/pass Package instead of a real
     * `denomination`. Mutual exclusivity between the two is enforced
     * in UpdatePackageCatalogCodeRequest, not here.
     */
    public function updateCatalogCode(UpdatePackageCatalogCodeRequest $request, Package $package): JsonResponse
    {
        $package->update(['catalog_code' => $request->validated('catalog_code')]);
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
