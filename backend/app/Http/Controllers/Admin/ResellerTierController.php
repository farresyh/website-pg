<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ResellerApi\CatalogController as ResellerApiCatalogController;
use App\Http\Requests\Admin\StoreResellerTierRequest;
use App\Http\Requests\Admin\UpdateResellerTierRequest;
use App\Models\ResellerTier;
use App\Services\Cache\NextRevalidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * ADR-072/073 PR-B: CRUD for the `reseller_tiers` ladder (the fee-less
 * prepaid-wallet tiers a `Reseller` account is assigned to). super_admin
 * only — route-level `admin.role:super_admin` is the gate. Starts empty.
 *
 * Delete is soft-delete: `resellers.reseller_tier_id` is
 * restrict-on-delete at the DB, so a tier any reseller is currently
 * assigned to can't be hard-deleted — mirrors
 * `AffiliateMembershipTierController`'s own precedent.
 */
class ResellerTierController extends Controller
{
    public function index(): JsonResponse
    {
        $tiers = ResellerTier::query()
            ->withCount(['resellers' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['tiers' => $tiers]);
    }

    public function store(StoreResellerTierRequest $request): JsonResponse
    {
        $tier = ResellerTier::query()->create($request->validated());

        // ADR-091: a new tier could be created with show_on_price_list
        // already true — the public price list (Next Data Cache, `catalog`
        // tag) should reflect it without waiting out the 30s backstop TTL.
        NextRevalidation::purge();

        return response()->json($tier, 201);
    }

    public function update(UpdateResellerTierRequest $request, ResellerTier $reseller_tier): JsonResponse
    {
        $reseller_tier->update($request->validated());

        // ADR-091: covers show_on_price_list / markup_percent / sort_order
        // changes — called unconditionally rather than special-casing
        // which fields changed, same as every other admin write that
        // touches public-facing content.
        NextRevalidation::purge();

        // A1 hardening (2026-09-10 reseller-family audit): a markup_percent
        // change re-prices this tier's Reseller API catalog cache without
        // touching any Package/Game row, so the catalog-write choke point
        // alone wouldn't invalidate it — flush explicitly here too.
        ResellerApiCatalogController::forgetPricedCache();

        return response()->json($reseller_tier->fresh());
    }

    public function destroy(ResellerTier $reseller_tier): JsonResponse
    {
        $blocking = $reseller_tier->resellers()->count();

        if ($blocking > 0) {
            throw ValidationException::withMessages([
                'tier' => ["{$blocking} reseller(s) are assigned to this tier. Move them to another tier first."],
            ]);
        }

        $reseller_tier->delete();

        return response()->json(['message' => 'Tier deleted.']);
    }
}
