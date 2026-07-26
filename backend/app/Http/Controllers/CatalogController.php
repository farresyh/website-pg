<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Package;
use App\Models\Reseller;
use App\Services\Pricing\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Public, guest-callable game/package catalog (ADR-011, same no-auth
 * reasoning as CheckoutController/PlayerValidationController) — the
 * storefront's real data source, replacing
 * storefront/src/lib/placeholder-data.ts (docs/prd.md §14/§15's NEXT
 * SESSION pointer, "public catalog endpoint").
 *
 * Deliberately a separate controller from GameController (admin-only,
 * `admin.role`-gated): mixing a public read path into an admin
 * controller risks a routing mistake exposing admin-only data. Never
 * returns cost_price/reseller_cost_price/markup_percent/supplier_id/
 * supplier_package_ref (GameController::packages()'s own fields, the
 * platform's wholesale cost and margin) — only a computed
 * `selling_price_sen`, the same customer-facing price
 * CheckoutService prices an order at (PricingService, against the
 * Platform Owner Reseller row, per ADR-013).
 *
 * Lookup is always by slug, not the admin routes' numeric `{id}` — the
 * storefront's own URLs (`/order/[slug]`) are slug-based, and slug is
 * the one identifier safe to expose publicly as a lookup key.
 */
class CatalogController extends Controller
{
    /** ADR-014: same 60s TTL/invalidate-on-write discipline as GameController. */
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(private readonly PricingService $pricing)
    {
    }

    public function index(): JsonResponse
    {
        $games = Cache::remember(
            'catalog.public.games.index',
            self::CACHE_TTL_SECONDS,
            fn () => Game::query()
                ->where('is_active', true)
                ->with(['packages' => fn ($query) => $query->where('is_active', true)])
                ->orderBy('name')
                ->get()
                ->map(fn (Game $game) => $this->publicGameSummary($game))
                ->all(),
        );

        return response()->json($games);
    }

    public function show(string $slug): JsonResponse
    {
        $game = $this->findActiveGame($slug);

        if ($game === null) {
            return response()->json(['message' => 'Game not found.'], 404);
        }

        return response()->json($this->publicGameDetail($game));
    }

    public function packages(string $slug): JsonResponse
    {
        $game = $this->findActiveGame($slug);

        if ($game === null) {
            return response()->json(['message' => 'Game not found.'], 404);
        }

        $packages = Cache::remember(
            self::packagesCacheKey($game->id),
            self::CACHE_TTL_SECONDS,
            fn () => $game->packages()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Package $package) => $this->publicPackage($package))
                ->all(),
        );

        return response()->json($packages);
    }

    private function findActiveGame(string $slug): ?Game
    {
        return Game::query()->where('slug', $slug)->where('is_active', true)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function publicGameSummary(Game $game): array
    {
        $activePackages = $game->packages->where('is_active', true);
        $cheapest = $activePackages->isEmpty() ? null : $activePackages->min(
            fn (Package $package) => $this->sellingPriceSen($package),
        );

        return [
            'id' => $game->id,
            'slug' => $game->slug,
            'name' => $game->name,
            'category' => $game->category,
            'image_url' => $game->image_url,
            'banner_url' => $game->banner_url,
            'extra_field' => $game->validation_rules['extra_field'] ?? null,
            'player_validator_enabled' => $game->player_validator_enabled,
            'price_from_sen' => $cheapest,
            // Cast to a plain string, never left as a Carbon instance:
            // this app's `database` cache store corrupts any raw
            // object nested in a cached value on the next read
            // (confirmed live, see HeroSlideController's doc comment)
            // — a plain array of scalars is not, on its own, enough if
            // one of those "scalars" is secretly still a Carbon object.
            'created_at' => $game->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicGameDetail(Game $game): array
    {
        return [
            'id' => $game->id,
            'slug' => $game->slug,
            'name' => $game->name,
            'category' => $game->category,
            'image_url' => $game->image_url,
            'banner_url' => $game->banner_url,
            'extra_field' => $game->validation_rules['extra_field'] ?? null,
            'player_validator_enabled' => $game->player_validator_enabled,
            'seo_title' => $game->seo_title,
            'seo_title_local' => $game->seo_title_local,
            'seo_description' => $game->seo_description,
            'seo_description_local' => $game->seo_description_local,
            'seo_keywords' => $game->seo_keywords,
            'seo_og_image' => $game->seo_og_image,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicPackage(Package $package): array
    {
        return [
            'id' => $package->id,
            'name' => $package->name,
            'selling_price_sen' => $this->sellingPriceSen($package),
        ];
    }

    private function sellingPriceSen(Package $package): int
    {
        $reseller = Reseller::platformOwner();

        return $this->pricing->calculate(
            $package->cost_price,
            $package->reseller_cost_price,
            (float) $reseller->markup_pct,
        )->sellingPrice;
    }

    /**
     * ADR-014 invalidation seam, extended: GameController::
     * forgetIndexCache()/forgetPackagesCache() already run at every
     * write that changes what these listings show — this just adds
     * the public cache keys to those same, single choke points rather
     * than scattering new invalidation calls across GameController/
     * PackageController/SupplierProductController.
     */
    public static function forgetIndexCache(): void
    {
        Cache::forget('catalog.public.games.index');
    }

    public static function forgetPackagesCache(int $gameId): void
    {
        Cache::forget(self::packagesCacheKey($gameId));
        // A package price/status change also changes the index's
        // per-game price_from_sen — the index cache must go too.
        Cache::forget('catalog.public.games.index');
    }

    private static function packagesCacheKey(int $gameId): string
    {
        return "catalog.public.games.{$gameId}.packages";
    }
}
