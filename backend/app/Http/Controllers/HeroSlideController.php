<?php

namespace App\Http\Controllers;

use App\Models\HeroSlide;
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
 * Caches a plain array, never a raw Eloquent Collection: this app's
 * default `database` cache store serializes the cached value on
 * write and `unserialize()`s it on every cache HIT. Confirmed live
 * against a real running server (`php artisan serve`): a warm-cache
 * request reliably returned a broken `__PHP_Incomplete_Class_Name`
 * JSON body when this cached a raw Collection — real repeated
 * requests, not a one-off. Plain arrays serialize/unserialize
 * cleanly, which is why CatalogController's own cached values are
 * already plain arrays, not raw models — this mirrors that.
 */
class HeroSlideController extends Controller
{
    /** ADR-014: same 60s TTL/invalidate-on-write discipline as CatalogController. */
    private const CACHE_TTL_SECONDS = 60;

    public function index(): JsonResponse
    {
        $slides = Cache::remember(
            'catalog.public.hero_slides',
            self::CACHE_TTL_SECONDS,
            fn () => HeroSlide::query()->live()->orderBy('sort_order')->orderBy('id')->get()->toArray(),
        );

        return response()->json($slides);
    }

    public static function forgetCache(): void
    {
        Cache::forget('catalog.public.hero_slides');
    }
}
