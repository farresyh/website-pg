<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BrandingController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\GameController;
use App\Http\Requests\Affiliate\Storefront\UploadFaviconRequest;
use App\Http\Requests\Affiliate\Storefront\UploadImageRequest;
use App\Http\Requests\Settings\BulkMarkupRequest;
use App\Http\Requests\Settings\UpdateBrandingRequest;
use App\Http\Requests\Settings\UpdateFooterSettingsRequest;
use App\Http\Requests\Settings\UpdatePlatformSettingsRequest;
use App\Models\Affiliate;
use App\Models\AffiliateBranding;
use App\Models\AffiliateFooterSettings;
use App\Models\Package;
use App\Models\PlatformSettings;
use App\Models\PriceChangeLog;
use App\Services\Media\ImageIngestService;
use App\Services\Pricing\PackageMarkupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Mews\Purifier\Facades\Purifier;

/**
 * ADR-028 + its 2026-08-22 addendum: the admin-facing side of the
 * Settings screen's three tabs (Store Branding, Footer Settings,
 * Platform Settings). No maker-checker (decision 15) — plain
 * single-admin save, same tier as every field this controller writes.
 */
class SettingsController extends Controller
{
    /** ADR-089: mirrors the affiliate portal's own constants — see `Affiliate\Storefront\BrandingController`. */
    private const LOGO_MAX_EDGE = 512;

    private const FAVICON_MAX_EDGE = 512;

    public function __construct(
        private readonly PackageMarkupService $markup,
        private readonly ImageIngestService $images,
    ) {}

    public function index(): JsonResponse
    {
        $affiliate = Affiliate::primary();

        return response()->json([
            'branding' => $this->brandingFor($affiliate),
            'footer' => $this->footerFor($affiliate),
            'platform' => PlatformSettings::current(),
        ]);
    }

    public function updateBranding(UpdateBrandingRequest $request): JsonResponse
    {
        $affiliate = Affiliate::primary();
        $branding = $this->brandingFor($affiliate);
        $branding->update($request->validated());
        BrandingController::forgetCache($affiliate->id);

        return response()->json($branding);
    }

    /** ADR-089: primary brand's logo — same pipeline as the affiliate portal's `uploadLogo`. */
    public function uploadLogo(UploadImageRequest $request): JsonResponse
    {
        $affiliate = Affiliate::primary();
        $path = $this->images->ingest($request->file('image'), "affiliate-logos/{$affiliate->id}.webp", self::LOGO_MAX_EDGE);

        $branding = $this->brandingFor($affiliate);
        $branding->logo_path = $path;
        $branding->save();
        BrandingController::forgetCache($affiliate->id);

        return response()->json($branding);
    }

    public function destroyLogo(): JsonResponse
    {
        $affiliate = Affiliate::primary();
        $branding = $this->brandingFor($affiliate);

        if ($branding->logo_path !== null) {
            $this->images->delete($branding->logo_path);
            $branding->logo_path = null;
            $branding->save();
            BrandingController::forgetCache($affiliate->id);
        }

        return response()->json($branding);
    }

    /** ADR-089: primary brand's favicon — separate square asset, see `UploadFaviconRequest`. */
    public function uploadFavicon(UploadFaviconRequest $request): JsonResponse
    {
        $affiliate = Affiliate::primary();
        $path = $this->images->ingest($request->file('image'), "affiliate-favicons/{$affiliate->id}.webp", self::FAVICON_MAX_EDGE);

        $branding = $this->brandingFor($affiliate);
        $branding->favicon_path = $path;
        $branding->save();
        BrandingController::forgetCache($affiliate->id);

        return response()->json($branding);
    }

    public function destroyFavicon(): JsonResponse
    {
        $affiliate = Affiliate::primary();
        $branding = $this->brandingFor($affiliate);

        if ($branding->favicon_path !== null) {
            $this->images->delete($branding->favicon_path);
            $branding->favicon_path = null;
            $branding->save();
            BrandingController::forgetCache($affiliate->id);
        }

        return response()->json($branding);
    }

    /**
     * Decision 12: `terms_content`/`privacy_content`/`about_us_content`/
     * `footer_text` are sanitized here (the `rich_text` Purifier
     * profile) before being stored — the FormRequest validates shape
     * only, it doesn't transform. Stored value keeps any `{store_name}`
     * token raw (decision 13) — substitution happens only where this
     * content is served to the storefront (Public\BrandingController).
     */
    public function updateFooter(UpdateFooterSettingsRequest $request): JsonResponse
    {
        $affiliate = Affiliate::primary();
        $footer = $this->footerFor($affiliate);

        $data = $request->validated();
        foreach (['footer_text', 'terms_content', 'privacy_content', 'about_us_content'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $data[$field] = Purifier::clean($data[$field], 'rich_text');
            }
        }

        $footer->update($data);
        BrandingController::forgetCache($affiliate->id);

        return response()->json($footer);
    }

    public function updatePlatform(UpdatePlatformSettingsRequest $request): JsonResponse
    {
        $settings = PlatformSettings::current();
        $settings->update($request->validated());

        return response()->json($settings);
    }

    /**
     * SET-2's bulk markup action (decision 4's mechanics, settled at
     * build time): a one-time bulk-update over every currently-active
     * Package, not a stored default for new packages — that's a
     * separate, unbuilt concern. Mirrors PackageController::
     * updateMarkup()'s own PriceChangeLog write exactly, one row per
     * package actually changed.
     */
    public function bulkMarkup(BulkMarkupRequest $request): JsonResponse
    {
        $markupPercent = (float) $request->validated('markup_percent');
        $changed = 0;
        $gameIds = [];

        DB::transaction(function () use ($markupPercent, &$changed, &$gameIds) {
            Package::query()->where('is_active', true)->chunkById(100, function ($packages) use ($markupPercent, &$changed, &$gameIds) {
                foreach ($packages as $package) {
                    $newStandardSellingPrice = $this->markup->calculateStandardSellingPrice($package->cost_price, $markupPercent);

                    if ($newStandardSellingPrice === $package->standard_selling_price && (float) $package->markup_percent === $markupPercent) {
                        continue;
                    }

                    PriceChangeLog::query()->create([
                        'price_sync_run_id' => null,
                        'package_id' => $package->id,
                        'old_cost_price' => $package->cost_price,
                        'new_cost_price' => $package->cost_price,
                        'old_standard_selling_price' => $package->standard_selling_price,
                        'new_standard_selling_price' => $newStandardSellingPrice,
                    ]);

                    $package->update([
                        'markup_percent' => $markupPercent,
                        'standard_selling_price' => $newStandardSellingPrice,
                    ]);

                    $changed++;
                    $gameIds[$package->game_id] = true;
                }
            });
        });

        GameController::forgetIndexCache();
        foreach (array_keys($gameIds) as $gameId) {
            GameController::forgetPackagesCache((int) $gameId);
        }

        return response()->json(['packages_updated' => $changed]);
    }

    private function brandingFor(Affiliate $affiliate): AffiliateBranding
    {
        return AffiliateBranding::query()->firstOrCreate(
            ['affiliate_id' => $affiliate->id],
            ['store_name' => $affiliate->business_name],
        );
    }

    private function footerFor(Affiliate $affiliate): AffiliateFooterSettings
    {
        return AffiliateFooterSettings::query()->firstOrCreate(['affiliate_id' => $affiliate->id]);
    }
}
