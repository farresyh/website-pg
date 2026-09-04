<?php

namespace App\Http\Controllers;

use App\Models\Affiliate;
use App\Models\AffiliateSeoSettings;
use App\Models\CrawlerRule;
use App\Models\Redirect;
use App\Models\SeoScript;
use App\Services\Cache\NextRevalidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-029: public, guest-callable storefront SEO data — settings/
 * templates/pixel IDs for generateMetadata(), redirects for
 * middleware.ts's in-memory cache (decision 9), scripts for layout
 * injection (addendum 2 decision 13), crawler rules for app/robots.ts
 * (addendum 2 decision 14). Same no-auth reasoning as BrandingController
 * (ADR-011) — another public call site for Affiliate::primary().
 */
class SeoController extends Controller
{
    private const CACHE_TTL_SECONDS = 60;

    public function settings(): JsonResponse
    {
        $affiliate = Affiliate::primary();

        $payload = Cache::remember(
            "catalog.public.seo.{$affiliate->id}",
            self::CACHE_TTL_SECONDS,
            function () use ($affiliate) {
                $settings = AffiliateSeoSettings::query()->where('affiliate_id', $affiliate->id)->first();

                return [
                    'default_meta_title' => $settings?->default_meta_title,
                    'default_meta_description' => $settings?->default_meta_description,
                    'default_og_image' => $settings?->default_og_image,
                    'meta_title_template' => $settings?->meta_title_template,
                    'meta_description_template' => $settings?->meta_description_template,
                    'ga_measurement_id' => $settings?->ga_measurement_id,
                    'fb_pixel_id' => $settings?->fb_pixel_id,
                    'tiktok_pixel_id' => $settings?->tiktok_pixel_id,
                    'schema_organization_enabled' => $settings?->schema_organization_enabled ?? true,
                    'schema_product_enabled' => $settings?->schema_product_enabled ?? true,
                    'schema_breadcrumb_enabled' => $settings?->schema_breadcrumb_enabled ?? true,
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
        $affiliate = Affiliate::primary();

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
        $affiliate = Affiliate::primary();
        $fromPath = (string) $request->input('from_path');

        Redirect::query()
            ->where('affiliate_id', $affiliate->id)
            ->where('from_path', $fromPath)
            ->increment('hit_count');

        return response()->json(null, 204);
    }

    public function scripts(): JsonResponse
    {
        $affiliate = Affiliate::primary();

        $payload = Cache::remember(
            "catalog.public.seo_scripts.{$affiliate->id}",
            self::CACHE_TTL_SECONDS,
            fn () => SeoScript::query()
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('affiliate_id')->orWhere('affiliate_id', $affiliate->id))
                ->orderBy('priority')
                ->get(['location', 'code', 'priority'])
                ->toArray(),
        );

        return response()->json($payload);
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
        Cache::forget("catalog.public.seo_scripts.{$affiliateId}");
        NextRevalidation::purge(); // ADR-071 PR2 — robots.txt stays force-dynamic, not in the Next cache
    }

    public static function forgetRobotsCache(): void
    {
        Cache::forget('catalog.public.crawler_rules');
    }
}
