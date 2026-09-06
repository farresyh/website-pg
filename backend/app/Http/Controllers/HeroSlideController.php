<?php

namespace App\Http\Controllers;

use App\Models\Affiliate;
use App\Models\HeroSlide;
use App\Services\Cache\NextRevalidation;
use App\Support\StorefrontBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Public, guest-callable homepage hero-banner content (ADR-011, same
 * no-auth reasoning as CatalogController) — docs/prd.md §14/§15
 * backlog: "Hero Banner / Campaign management." Replaces the
 * storefront's static HERO_SLIDES placeholder.
 *
 * `HeroSlide::scopeLive()` filters both `is_active` and the optional
 * `starts_at`/`ends_at` schedule window — a slide past its end date
 * must disappear on its own, not require an admin to manually
 * deactivate it.
 *
 * ADR-060 PR-6: resolved per `Host` brand. A brand serves its OWN
 * active slides; a brand with zero active slides falls back to the
 * global set (null `affiliate_id`, edited in `/admin/hero-slides`) —
 * "null = global", keyed on effective content, not row existence
 * (planning addendum Q15). Cache is per brand; an admin edit of a
 * global slide busts every brand's key (planning addendum decision 9).
 *
 * Caches a plain array, never a raw Eloquent Collection: this app's
 * default `database` cache store serializes the cached value on
 * write and `unserialize()`s it on every cache HIT. Confirmed live
 * against a real running server (`php artisan serve`): a warm-cache
 * request reliably returned a broken `__PHP_Incomplete_Class_Name`
 * JSON body when this cached a raw Collection.
 */
class HeroSlideController extends Controller
{
    /** ADR-014: same 60s TTL/invalidate-on-write discipline as CatalogController. */
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(private readonly StorefrontBrand $brand) {}

    public function index(): JsonResponse
    {
        $brandId = $this->brand->get()->id;

        $slides = Cache::remember(
            self::cacheKey($brandId),
            self::CACHE_TTL_SECONDS,
            function () use ($brandId) {
                $own = HeroSlide::query()->live()
                    ->where('affiliate_id', $brandId)
                    ->orderBy('sort_order')->orderBy('id')
                    ->get();

                $slides = $own->isNotEmpty()
                    ? $own
                    : HeroSlide::query()->live()
                        ->whereNull('affiliate_id')
                        ->orderBy('sort_order')->orderBy('id')
                        ->get();

                return $slides->map(fn (HeroSlide $s) => [
                    'id' => $s->id,
                    'eyebrow' => $s->eyebrow,
                    'title' => $s->title,
                    'description' => $s->description,
                    'image_url' => $s->resolvedImageUrl(),
                    'price_from_sen' => $s->price_from_sen,
                    'primary_cta_label' => $s->primary_cta_label,
                    'primary_cta_href' => $s->primary_cta_href,
                    'secondary_cta_label' => $s->secondary_cta_label,
                    'secondary_cta_href' => $s->secondary_cta_href,
                ])->all();
            },
        );

        return response()->json($slides);
    }

    /**
     * ADR-060 PR-6: pass the brand id from an affiliate's own slide
     * write (only that brand's key needs clearing); pass nothing from
     * an admin global-slide write — every brand may have been falling
     * back to that set, so every per-brand key is cleared.
     */
    public static function forgetCache(?int $brandId = null): void
    {
        $ids = $brandId !== null
            ? [$brandId]
            : Affiliate::withTrashed()->pluck('id')->all();

        foreach ($ids as $id) {
            Cache::forget(self::cacheKey($id));
        }

        NextRevalidation::purge(); // ADR-071 PR2
    }

    private static function cacheKey(int $brandId): string
    {
        return "catalog.public.hero_slides.brand.{$brandId}";
    }
}
