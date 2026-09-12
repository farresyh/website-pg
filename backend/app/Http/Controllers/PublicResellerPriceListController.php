<?php

namespace App\Http\Controllers;

use App\Services\Reseller\PublicResellerPriceListService;
use App\Support\StorefrontBrand;
use Illuminate\Http\JsonResponse;

/**
 * ADR-091: the public Reseller Price List page's backend. Guest-callable
 * (ADR-011's trust model, same as CatalogController/PaymentMethodCatalog
 * Controller) — sits under the `catalog` prefix's `storefront.brand`
 * group so `X-Storefront-Host` resolves which brand is asking, but the
 * only thing that resolution is used for here is the `is_owned` check:
 * unlike catalog/pricing, this page's content is platform-wide, not
 * per-brand (`reseller_tiers` isn't an affiliate concept).
 *
 * Deliberately a separate controller from `Admin\ResellerTierController`
 * — same reasoning `PaymentMethodCatalogController`'s own doc comment
 * gives for staying apart from its admin-only counterpart.
 */
class PublicResellerPriceListController extends Controller
{
    public function __construct(
        private readonly StorefrontBrand $brand,
        private readonly PublicResellerPriceListService $service,
    ) {}

    public function index(): JsonResponse
    {
        if (! $this->brand->get()->is_owned) {
            // Same empty-array-means-off contract as MembershipController::
            // plans() — no 404 special case, the frontend just renders
            // "no page" for this shape.
            return response()->json(['tiers' => [], 'games' => []]);
        }

        return response()->json($this->service->build());
    }
}
