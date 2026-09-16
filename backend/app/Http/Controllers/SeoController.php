<?php

namespace App\Http\Controllers;

use App\Models\Affiliate;
use App\Models\AffiliateSeoSettings;
use App\Models\CrawlerRule;
use App\Models\Redirect;
use App\Services\Cache\NextRevalidation;
use App\Support\StorefrontBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-029: public, guest-callable storefront SEO data — settings/
 * templates/pixel IDs for generateMetadata(), redirects for
 * middleware.ts's in-memory cache (decision 9), crawler rules for
 * app/robots.ts (addendum 2 decision 14). Same no-auth reasoning as
 * BrandingController (ADR-011). Brand resolved per `Host` (ADR-060,
 * `storefront.brand` middleware); the per-cache-key `{affiliate->id}`
 * already isolates brands. Merging the primary's admin-central meta
 * templates with a third-party brand's own pixel IDs (ADR-060 addendum)
 * is PR-6 — PR-2 just resolves the right row.
 *
 * `scripts()` (addendum 2 decision 13's admin-authored <script> feed)
 * removed by ADR-101 decision 9 — it defeated any CSP allowlist by
 * design; the `ga_measurement_id`/`fb_pixel_id`/`tiktok_pixel_id` fields
 * above already cover the only three vendors it was ever used for.
 */
class SeoController extends Controller
{
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(private readonly StorefrontBrand $brand) {}

    public function settings(): JsonResponse
    {
        $affiliate = $this->brand->get();

        $payload = Cache::remember(
            "catalog.public.seo.{$affiliate->id}",
            self::CACHE_TTL_SECONDS,
            function () use ($affiliate) {
                $primary = Affiliate::query()->where('is_primary', true)->first();
                $primarySettings = $primary ? AffiliateSeoSettings::query()->where('affiliate_id', $primary->id)->first() : null;
                $affiliateSettings = ($primary && $affiliate->id === $primary->id)
                    ? $primarySettings
                    : AffiliateSeoSettings::query()->where('affiliate_id', $affiliate->id)->first();

                // ADR-060 addendum: pixel IDs are per-brand; meta templates,
                // default meta, and schema toggles are admin-central on the primary.
                return [
                    'default_meta_title' => $primarySettings?->default_meta_title,
                    'default_meta_description' => $primarySettings?->default_meta_description,
                    'default_og_image' => $primarySettings?->default_og_image,
                    'meta_title_template' => $primarySettings?->meta_title_template,
                    'meta_description_template' => $primarySettings?->meta_description_template,
                    'ga_measurement_id' => $affiliateSettings?->ga_measurement_id,
                    'fb_pixel_id' => $affiliateSettings?->fb_pixel_id,
                    'tiktok_pixel_id' => $affiliateSettings?->tiktok_pixel_id,
                    'schema_organization_enabled' => $primarySettings?->schema_organization_enabled ?? true,
                    'schema_product_enabled' => $primarySettings?->schema_product_enabled ?? true,
                    'schema_breadcrumb_enabled' => $primarySettings?->schema_breadcrumb_enabled ?? true,
                ];
            },
        );

        return response()->json($payload);
    }

    /**
     * Consumed by storefront middleware.ts (decision 9) to build its
     * in-memory redirect cache — a cache-miss costs one call here, every
     * other request in the TTL window costs nothing.
     */
    public function redirects(): JsonResponse
    {
        $affiliate = $this->brand->get();

        $payload = Cache::remember(
            "catalog.public.redirects.{$affiliate->id}",
            self::CACHE_TTL_SECONDS,
            fn () => Redirect::query()
                ->where('affiliate_id', $affiliate->id)
                ->get(['id', 'from_path', 'to_path', 'status_code'])
                ->toArray(),
        );

        return response()->json($payload);
    }

    /**
     * Fire-and-forget from middleware.ts on an actual redirect hit
     * (addendum 2 decision 15). Silently no-ops on an unknown path —
     * this only ever fires right after a successful cache-lookup match,
     * a miss here means the DB row was deleted between cache refreshes.
     */
    public function recordRedirectHit(Request $request): JsonResponse
    {
        $affiliate = $this->brand->get();
        $fromPath = (string) $request->input('from_path');

        Redirect::query()
            ->where('affiliate_id', $affiliate->id)
            ->where('from_path', $fromPath)
            ->increment('hit_count');

        return response()->json(null, 204);
    }

    /**
     * Consumed by storefront app/robots.ts (addendum 2 decision 14).
     * `crawler_default_disallow_paths` (2026-08-22 refinement, founder
     * request) is merged into every *allowed* bot's own disallow list
     * here — server-side, not left to the storefront — because a
     * robots.txt `User-agent` block never inherits from `*`; a bot
     * with its own named block ignores whatever `*` disallows
     * entirely. Merging once here means a path added to the shared
     * default covers every bot automatically, including ones added to
     * `crawler_rules` later, with zero per-row duplication.
     */
    public function robots(): JsonResponse
    {
        // Crawler rules are platform-central (`CrawlerRule` is not
        // brand-scoped) and this cache key is global — kept on the
        // primary, not the resolved brand, so one brand's robots request
        // can't poison another's cached disallow list.
        $affiliate = Affiliate::primary();

        $payload = Cache::remember(
            'catalog.public.crawler_rules',
            self::CACHE_TTL_SECONDS,
            function () use ($affiliate) {
                $settings = AffiliateSeoSettings::query()->where('affiliate_id', $affiliate->id)->first();
                $defaultDisallow = $settings?->crawler_default_disallow_paths ?? [];

                return CrawlerRule::query()
                    ->orderBy('sort_order')
                    ->get(['bot_name', 'user_agent', 'is_allowed', 'crawl_delay', 'disallow_paths'])
                    ->map(function (CrawlerRule $rule) use ($defaultDisallow) {
                        if ($rule->is_allowed) {
                            $rule->disallow_paths = array_values(array_unique([
                                ...($rule->disallow_paths ?? []),
                                ...$defaultDisallow,
                            ]));
                        }

                        return $rule;
                    })
                    ->toArray();
            },
        );

        return response()->json($payload);
    }

    public static function forgetCache(int $affiliateId): void
    {
        Cache::forget("catalog.public.seo.{$affiliateId}");
        Cache::forget("catalog.public.redirects.{$affiliateId}");

        $primary = Affiliate::query()->where('is_primary', true)->first();
        if ($primary && $affiliateId === $primary->id) {
            $otherAffiliates = Affiliate::query()->where('id', '!=', $primary->id)->pluck('id');
            foreach ($otherAffiliates as $otherId) {
                Cache::forget("catalog.public.seo.{$otherId}");
            }
        }

        NextRevalidation::purge(); // ADR-071 PR2 — robots.txt stays force-dynamic, not in the Next cache
    }

    public static function forgetRobotsCache(): void
    {
        Cache::forget('catalog.public.crawler_rules');
    }
}
