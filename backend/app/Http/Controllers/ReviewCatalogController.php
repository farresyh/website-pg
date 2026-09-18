<?php

namespace App\Http\Controllers;

use App\Models\Affiliate;
use App\Models\Game;
use App\Models\Review;
use App\Services\Cache\NextRevalidation;
use App\Services\Review\ReviewStatus;
use App\Support\ContactMask;
use App\Support\StorefrontBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Public, guest-callable approved reviews endpoint for the storefront homepage.
 * Returns latest approved reviews with masked customer names for privacy.
 *
 * Both endpoints are tenant-scoped to the resolved storefront brand
 * (`X-Storefront-Host` → `StorefrontBrand`): an affiliate whitelabel
 * storefront only ever surfaces reviews from its own orders, the primary
 * brand also matching any null-`affiliate_id` order. `index()` reuses
 * `Order::scopeForStorefrontBrand()` (the scope PR #158 added for the
 * public track-order lookup); `gameReviews()` inlines the same predicate
 * alongside its `game_id` filter.
 */
class ReviewCatalogController extends Controller
{
    private const CACHE_TTL_SECONDS = 60;

    private const CACHE_KEY = 'catalog.public.reviews';

    private const HOMEPAGE_REVIEW_LIMIT = 12;

    /**
     * Caps how many of a single customer's reviews can appear in the
     * homepage carousel. Without this, an early-launch site with few
     * distinct reviewers has its "latest N" window dominated by
     * whichever one customer happens to have ordered (and reviewed)
     * most recently and repeatedly — real reviews, but a "What
     * Customers Say" carousel that reads as synthetic. Found via the
     * antislop-ui audit (docs/anti-slop/audit-001-2026-09-18.md #3).
     */
    private const MAX_REVIEWS_PER_CUSTOMER = 2;

    public function index(StorefrontBrand $brand): JsonResponse
    {
        $affiliate = $brand->get();

        $reviews = Cache::remember(
            self::homepageCacheKey($affiliate->id),
            self::CACHE_TTL_SECONDS,
            function () use ($affiliate) {
                $pool = Review::query()
                    ->where('status', ReviewStatus::Approved->value)
                    ->whereNotNull('comment')
                    ->whereHas('order', fn ($q) => $q->forStorefrontBrand($affiliate))
                    ->with([
                        'order:id,customer_name,customer_email,game_id,package_id',
                        'order.game:id,name',
                        'order.package:id,name',
                    ])
                    ->orderByDesc('created_at')
                    ->take(self::HOMEPAGE_REVIEW_LIMIT * 4)
                    ->get();

                $perCustomerCount = [];
                $diversified = [];

                foreach ($pool as $r) {
                    $customerKey = $r->order?->customer_email ?? $r->order?->customer_name ?? "review-{$r->id}";
                    $perCustomerCount[$customerKey] = ($perCustomerCount[$customerKey] ?? 0) + 1;

                    if ($perCustomerCount[$customerKey] > self::MAX_REVIEWS_PER_CUSTOMER) {
                        continue;
                    }

                    $diversified[] = $r;

                    if (count($diversified) >= self::HOMEPAGE_REVIEW_LIMIT) {
                        break;
                    }
                }

                return collect($diversified)
                    ->map(fn (Review $r) => [
                        'id' => $r->id,
                        'name' => ContactMask::name($r->order?->customer_name)
                            ?? ContactMask::email($r->order?->customer_email)
                            ?? 'Verified Customer',
                        'rating' => $r->rating,
                        'comment' => $r->comment,
                        'game_name' => $r->order?->game?->name,
                        'package_name' => $r->order?->package?->name,
                        'created_at' => $r->created_at?->toIso8601String(),
                    ])
                    ->all();
            }
        );

        return response()->json($reviews);
    }

    public function gameReviews(string $slug, StorefrontBrand $brand): JsonResponse
    {
        $game = Game::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if ($game === null) {
            return response()->json(['message' => 'Game not found.'], 404);
        }

        $affiliate = $brand->get();
        $isPrimary = (bool) $affiliate->is_primary;
        $cacheKey = "catalog.game_reviews.{$affiliate->id}.{$game->id}";

        $payload = Cache::remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            function () use ($game, $affiliate, $isPrimary) {
                $baseQuery = Review::query()
                    ->where('status', ReviewStatus::Approved->value)
                    ->whereHas('order', function ($q) use ($game, $affiliate, $isPrimary) {
                        $q->where('game_id', $game->id)
                            ->where(function ($sub) use ($affiliate, $isPrimary) {
                                $sub->where('affiliate_id', $affiliate->id);
                                if ($isPrimary) {
                                    $sub->orWhereNull('affiliate_id');
                                }
                            });
                    });

                $reviewCount = (clone $baseQuery)->count();
                $avgRating = $reviewCount > 0
                    ? round((float) (clone $baseQuery)->avg('rating'), 1)
                    : 0.0;

                $reviews = (clone $baseQuery)
                    ->whereNotNull('comment')
                    ->where('comment', '!=', '')
                    ->with([
                        'order:id,customer_name,customer_email,game_id,package_id',
                        'order.package:id,name',
                    ])
                    ->orderByDesc('created_at')
                    ->take(3)
                    ->get()
                    ->map(fn (Review $r) => [
                        'id' => $r->id,
                        'name' => ContactMask::name($r->order?->customer_name)
                            ?? ContactMask::email($r->order?->customer_email)
                            ?? 'Verified Customer',
                        'rating' => $r->rating,
                        'comment' => $r->comment,
                        'package_name' => $r->order?->package?->name,
                        'created_at' => $r->created_at?->toIso8601String(),
                    ])
                    ->all();

                return [
                    'average_rating' => $avgRating,
                    'review_count' => $reviewCount,
                    'reviews' => $reviews,
                ];
            }
        );

        return response()->json($payload);
    }

    /**
     * A null `$affiliateId` (a primary-brand order carries no `affiliate_id`)
     * normalises to the primary affiliate's id — the same brand key `index()`
     * writes under for the primary storefront.
     */
    public static function forgetCache(?int $affiliateId = null, ?int $gameId = null): void
    {
        $affiliateId ??= Affiliate::primary()->id;

        Cache::forget(self::homepageCacheKey($affiliateId));

        if ($gameId !== null) {
            Cache::forget("catalog.game_reviews.{$affiliateId}.{$gameId}");
        }

        NextRevalidation::purge();
    }

    private static function homepageCacheKey(int $affiliateId): string
    {
        return self::CACHE_KEY.".{$affiliateId}";
    }
}
