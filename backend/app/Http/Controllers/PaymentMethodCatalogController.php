<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Public, guest-callable payment-method listing (ADR-011, same no-auth
 * reasoning as CatalogController/HeroSlideController) — ADR-022's
 * newest addendum, decision 5. Replaces the storefront's hardcoded
 * `PLACEHOLDER_PAYMENT_CHANNELS` (storefront/src/lib/placeholder-
 * data.ts), which was never actually live for either gateway.
 *
 * Deliberately a separate controller from
 * Middleware\PaymentMethodController (admin-only, `admin.role`-gated):
 * mixing a public read path into an admin controller risks a routing
 * mistake exposing admin-only data, same reasoning CatalogController's
 * own doc comment gives for staying separate from GameController.
 *
 * Only ever returns `channel_code`/`label`/`category` — `gateway` and
 * `method_key` are internal routing details, never a customer choice
 * or something a customer needs to see (ADR-022's newest addendum,
 * decision 5's own explicit constraint).
 */
class PaymentMethodCatalogController extends Controller
{
    /** ADR-014: same 60s TTL/invalidate-on-write discipline as CatalogController/HeroSlideController. */
    private const CACHE_TTL_SECONDS = 60;

    private const CACHE_KEY = 'catalog.public.payment_methods';

    public function index(): JsonResponse
    {
        $channels = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            // Plain arrays only, never a raw Eloquent Collection/Model —
            // this app's `database` cache store corrupts a cached value
            // that still has real objects nested inside it on the next
            // read (see CatalogController's/HeroSlideController's own
            // doc comments for the full story).
            fn () => PaymentMethod::query()
                ->where('is_active', true)
                ->orderBy('category')
                ->orderBy('label')
                ->get()
                ->map(fn (PaymentMethod $method) => [
                    'channel_code' => $method->channel_code,
                    'label' => $method->label,
                    'category' => $method->category,
                ])
                ->all(),
        );

        return response()->json($channels);
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
