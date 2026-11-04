<?php

namespace App\Http\Controllers;

use App\Http\Requests\Campaign\RecordPromoClickRequest;
use App\Models\PromoCode;
use App\Models\PromoCodeClick;
use App\Support\StorefrontBrand;
use Illuminate\Http\Response;

/**
 * ADR-118 V16: logs a `?promo=CODE` visit for the campaign scorecard. The
 * storefront sends it once per tab. Every answer is 204, so the endpoint
 * never tells a caller whether a code exists; only a real code of this
 * storefront's brand is recorded.
 */
class PromoCodeClickController extends Controller
{
    public function __construct(private readonly StorefrontBrand $storefrontBrand) {}

    public function store(RecordPromoClickRequest $request): Response
    {
        $codeId = PromoCode::query()
            ->where('code', PromoCode::normalise($request->validated('code')))
            ->whereHas('campaign', fn ($q) => $q->where('affiliate_id', $this->storefrontBrand->get()->id))
            ->value('id');

        if ($codeId !== null) {
            PromoCodeClick::query()->create(['promo_code_id' => $codeId]);
        }

        return response()->noContent();
    }
}
