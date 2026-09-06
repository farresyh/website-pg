<?php

namespace App\Http\Controllers\Affiliate\Storefront;

use App\Http\Controllers\Affiliate\Concerns\AssertsAffiliateWritable;
use App\Http\Controllers\Controller;
use App\Http\Controllers\SeoController as PublicSeoController;
use App\Http\Requests\Affiliate\Storefront\UpdateSeoRequest;
use App\Models\AffiliateSeoSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-060 PR-6 — the pixel-ID write path for the Branding tab. Its own
 * controller (one model per controller), mirroring the admin
 * `Settings\*` vs `Admin\SeoController` split; the Branding tab UI calls
 * this endpoint alongside `Storefront\BrandingController::update`.
 *
 * Meta templates, OG image, and schema toggles are deliberately NOT
 * here — they stay admin-central (ADR-060 absorb-addendum).
 */
class SeoController extends Controller
{
    use AssertsAffiliateWritable;

    public function update(UpdateSeoRequest $request): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();
        $this->assertWritable($affiliate);

        $settings = AffiliateSeoSettings::query()->firstOrNew(['affiliate_id' => $affiliate->id]);
        // Empty string → NULL, so a cleared field is genuinely unset
        // rather than an empty pixel snippet on the storefront.
        foreach ($request->validated() as $key => $value) {
            $settings->{$key} = ($value === '' || $value === null) ? null : $value;
        }
        $settings->save();

        PublicSeoController::forgetCache($affiliate->id);

        return $this->show($request);
    }

    public function show(Request $request): JsonResponse
    {
        $settings = AffiliateSeoSettings::query()->first();

        return response()->json([
            'ga_measurement_id' => $settings?->ga_measurement_id,
            'fb_pixel_id' => $settings?->fb_pixel_id,
            'tiktok_pixel_id' => $settings?->tiktok_pixel_id,
        ]);
    }
}
