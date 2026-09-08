<?php

namespace App\Http\Controllers\Affiliate\Storefront;

use App\Http\Controllers\Affiliate\Concerns\AssertsAffiliateWritable;
use App\Http\Controllers\BrandingController as PublicBrandingController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\SeoController as PublicSeoController;
use App\Http\Requests\Affiliate\Storefront\UpdateBrandingRequest;
use App\Http\Requests\Affiliate\Storefront\UploadImageRequest;
use App\Models\AffiliateBranding;
use App\Models\AffiliateSeoSettings;
use App\Services\Media\ImageIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-060 PR-6 — the Branding tab of the affiliate portal's Storefront
 * screen: store identity (name / description / contacts / social links),
 * logo upload, and the three tracking-pixel IDs.
 *
 * The GET aggregates two models (`affiliate_branding` + the pixel fields
 * of `affiliate_seo_settings`) so the tab loads in one call; the two
 * write paths stay split — pixels go to `Storefront\SeoController` — so
 * each controller owns one model, mirroring the admin
 * `Settings\*` / `Admin\SeoController` split.
 *
 * Both models are `BelongsToAffiliate`, but every query here filters by
 * `affiliateOwner()->id` explicitly (`withoutAffiliateScope()`) rather
 * than leaning on the global scope — the same by-hand tenant scoping the
 * rest of the portal controllers use (`DomainController`/`ProfileController`).
 */
class BrandingController extends Controller
{
    use AssertsAffiliateWritable;

    /** ADR-060 PR-6 planning addendum decision 4: logo longest edge. */
    private const LOGO_MAX_EDGE = 512;

    public function __construct(private readonly ImageIngestService $images) {}

    public function show(Request $request): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();
        $branding = AffiliateBranding::withoutAffiliateScope()->where('affiliate_id', $affiliate->id)->first();
        $seo = AffiliateSeoSettings::withoutAffiliateScope()->where('affiliate_id', $affiliate->id)->first();

        return response()->json([
            'branding' => [
                'store_name' => $branding?->store_name ?? $affiliate->business_name,
                'description' => $branding?->description,
                'logo_url' => $branding?->logo_url,
                'theme_preset' => $branding?->theme_preset ?? 'default',
                'support_email' => $branding?->support_email,
                'support_phone' => $branding?->support_phone,
                'telegram_contact_link' => $branding?->telegram_contact_link,
                'social_links' => $branding?->social_links ?: null,
            ],
            'seo' => [
                'ga_measurement_id' => $seo?->ga_measurement_id,
                'fb_pixel_id' => $seo?->fb_pixel_id,
                'tiktok_pixel_id' => $seo?->tiktok_pixel_id,
            ],
            'writable' => $affiliate->status === 'active',
        ]);
    }

    public function update(UpdateBrandingRequest $request): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();
        $this->assertWritable($affiliate);

        $data = $request->validated();
        // Drop blank social entries so the storefront renders only the
        // links the affiliate actually filled in (a direct API caller
        // won't have done the frontend's own filtering).
        $data['social_links'] = array_filter(
            $data['social_links'] ?? [],
            fn ($value) => is_string($value) && trim($value) !== '',
        );

        $branding = AffiliateBranding::withoutAffiliateScope()->firstOrNew(['affiliate_id' => $affiliate->id]);
        $branding->fill($data);
        $branding->save();

        $this->flushBrandCaches($affiliate->id);

        return $this->show($request);
    }

    public function uploadLogo(UploadImageRequest $request): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();
        $this->assertWritable($affiliate);

        // Fixed path — a re-upload overwrites in place, no orphan.
        $path = $this->images->ingest(
            $request->file('image'),
            "affiliate-logos/{$affiliate->id}.webp",
            self::LOGO_MAX_EDGE,
        );

        $branding = AffiliateBranding::withoutAffiliateScope()->firstOrNew(['affiliate_id' => $affiliate->id]);
        // A logo upload before the identity form is ever saved must not
        // write a NULL store_name (it is NOT NULL on the table).
        $branding->store_name ??= $affiliate->business_name;
        $branding->logo_path = $path;
        $branding->save();

        $this->flushBrandCaches($affiliate->id);

        return $this->show($request);
    }

    public function destroyLogo(Request $request): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();
        $this->assertWritable($affiliate);

        $branding = AffiliateBranding::withoutAffiliateScope()->where('affiliate_id', $affiliate->id)->first();

        if ($branding?->logo_path !== null) {
            $this->images->delete($branding->logo_path);
            $branding->logo_path = null;
            $branding->save();
            $this->flushBrandCaches($affiliate->id);
        }

        return $this->show($request);
    }

    /**
     * ADR-060 PR-6 planning addendum decision 9: a branding save also
     * touches the SEO cache (the `{store_name}` token is substituted into
     * meta templates) and the catalog index (its response carries brand
     * identity) — cheap insurance against a stale name in `<head>`.
     */
    private function flushBrandCaches(int $affiliateId): void
    {
        PublicBrandingController::forgetCache($affiliateId);
        PublicSeoController::forgetCache($affiliateId);
        CatalogController::forgetIndexCache($affiliateId);
    }
}
