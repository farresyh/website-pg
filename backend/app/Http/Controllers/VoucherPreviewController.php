<?php

namespace App\Http\Controllers;

use App\Http\Requests\Voucher\PreviewVoucherRequest;
use App\Models\Game;
use App\Models\Package;
use App\Services\Campaign\CampaignService;
use App\Services\Campaign\InvalidPromoCodeException;
use App\Services\Campaign\PromoCustomer;
use App\Services\Joki\JokiCheckoutService;
use App\Services\Pricing\OrderPricingResolver;
use App\Services\Pricing\PricingService;
use App\Services\Voucher\InvalidVoucherException;
use App\Services\Voucher\VoucherService;
use App\Support\StorefrontBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * ADR-024 decision #1's "Apply" button — public, guest-checkout
 * endpoint (ADR-011, same as CatalogController/CheckoutController),
 * read-only: never locks or mutates a Voucher's `remaining` balance,
 * that only ever happens later inside the real
 * `POST /api/checkout` (CheckoutService::initiate()'s own call to
 * VoucherService::redeem()). Exists purely so the storefront can show
 * an accurate new total before the customer commits to anything.
 *
 * sellingPrice is recomputed here the same way CheckoutController
 * does — from the stored Package rows against the `Host`-resolved
 * storefront brand's wholesale tier + markup (ADR-060 PR-4c), never
 * trusted from the client (ORD-9) — so the discount preview is as real
 * as the final checkout's own number, not a client-side guess.
 */
class VoucherPreviewController extends Controller
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly VoucherService $vouchers,
        private readonly StorefrontBrand $storefrontBrand,
        private readonly JokiCheckoutService $jokiCheckout,
        private readonly OrderPricingResolver $pricingResolver,
        private readonly CampaignService $campaigns,
    ) {}

    public function store(PreviewVoucherRequest $request): JsonResponse
    {
        $data = $request->validated();

        $game = Game::query()->findOrFail($data['game_id']);
        $package = Package::query()->findOrFail($data['package_id']);

        if ($package->game_id !== $game->id) {
            throw ValidationException::withMessages([
                'package_id' => ['This package does not belong to the selected game.'],
            ]);
        }

        $brand = $this->storefrontBrand->get();
        $terms = $brand->storefrontPriceTerms();

        // Same wholesale-tier/markup basis CheckoutService prices the real
        // order at — `calculateForAffiliate` with a null `tierMarkupPct`
        // is byte-identical to the old `calculate()` for the primary /
        // lapsed brand (ADR-060 PR-4b).
        //
        // ADR-126 F1: a joki package's own price is never read; the climb is
        // priced by JokiCheckoutService, the seam checkout and its review
        // total use, so the preview's discount is the one checkout applies.
        $sellingPriceSen = $game->is_joki
            ? $this->jokiCheckout->resolve($package, (array) ($data['joki'] ?? []), $brand)->pricing->sellingPriceSen
            : $this->pricing->calculateForAffiliate(
                $package->cost_price,
                $package->standard_selling_price,
                $terms['tier'],
                $terms['markup'],
            )->sellingPrice;

        // ADR-118 V7: with a promo, the voucher is capped at the price after
        // it — the same quote checkout applies, Standard basis (decision 4).
        if (filled($data['promo_code'] ?? null)) {
            try {
                $sellingPriceSen = $this->campaigns->quote(
                    $data['promo_code'],
                    $brand->id,
                    $game->is_joki
                        ? $this->jokiCheckout->resolve($package, (array) ($data['joki'] ?? []), $brand)->pricing
                        : $this->pricingResolver->resolveStorefront($package->cost_price, $package->standard_selling_price, (float) $package->markup_percent, $terms['markup'], $terms['tier'], null),
                    PromoCustomer::at($game, $data['customer_email'], $data['customer_phone'], $data['player_id'], $data['server_id'] ?? null),
                )->pricing->sellingPriceSen;
            } catch (InvalidPromoCodeException $e) {
                throw ValidationException::withMessages(['promo_code' => [$e->getMessage()]]);
            }
        }

        try {
            $preview = $this->vouchers->preview(
                $data['voucher_code'],
                $data['customer_email'],
                $data['customer_phone'] ?? null,
                $sellingPriceSen,
                $brand->id,
            );
        } catch (InvalidVoucherException) {
            throw ValidationException::withMessages([
                'voucher_code' => ['This voucher code is not valid for this order.'],
            ]);
        }

        return response()->json([
            'selling_price' => $sellingPriceSen,
            'discount' => $preview->discountSen,
            'remaining_after' => $preview->remainingAfterSen,
        ]);
    }
}
