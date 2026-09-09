<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Services\Cache\NextRevalidation;
use App\Services\Review\ReviewStatus;
use App\Support\ContactMask;
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

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        NextRevalidation::purge();
    }
}
