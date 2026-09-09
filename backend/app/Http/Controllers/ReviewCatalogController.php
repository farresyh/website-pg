<?php

namespace App\Http\Controllers;

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
 */
class ReviewCatalogController extends Controller
{
    private const CACHE_TTL_SECONDS = 60;
    private const CACHE_KEY = 'catalog.public.reviews';

    public function index(): JsonResponse
    {
        $reviews = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            function () {
                return Review::query()
                    ->where('status', ReviewStatus::Approved->value)
                    ->whereNotNull('comment')
                    ->with([
                        'order:id,customer_name,customer_email,game_id,package_id',
                        'order.game:id,name',
                        'order.package:id,name',
                    ])
                    ->orderByDesc('created_at')
                    ->take(12)
                    ->get()
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
        $game = \App\Models\Game::query()
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

    public static function forgetCache(?int $affiliateId = null, ?int $gameId = null): void
    {
        Cache::forget(self::CACHE_KEY);
        if ($affiliateId !== null && $gameId !== null) {
            Cache::forget("catalog.game_reviews.{$affiliateId}.{$gameId}");
        }
        NextRevalidation::purge();
    }
}
