<?php

namespace App\Http\Controllers;

use App\Http\Requests\Games\UpdateGameRequest;
use App\Models\Game;
use App\Models\Package;
use App\Models\SupplierProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * GAME-1..5: Admin's Games & Packages management, and also the "link
 * to an existing Game" picker in the Price Sync Stage 2 promote flow
 * (SupplierProductController) — shared, not Admin- or
 * Middleware-specific data. GAME-6 (drag-and-drop reorder), GAME-9..11
 * (bulk sync/price actions — already covered by Product Manager's own
 * flow) and supplier_mappings/validation_rules/SEO field editing are
 * deliberately out of scope for this pass.
 */
class GameController extends Controller
{
    /**
     * ADR-014: 60s TTL, invalidated immediately on any write that
     * changes what these two listings show (see forgetIndexCache()/
     * forgetPackagesCache() and every call site of those). Pricing and
     * checkout are never cached — only these read-listing endpoints.
     */
    private const CACHE_TTL_SECONDS = 60;

    /**
     * `status` — `active`/`inactive`, omit for all (GAME-2). Only the
     * unfiltered listing (no search/status) is cached — a filtered
     * admin search goes straight to the DB rather than growing the
     * cache with one entry per distinct search string.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $status = $request->query('status');

        if ($search === null && $status === null) {
            return response()->json(Cache::remember(
                'catalog.games.index',
                self::CACHE_TTL_SECONDS,
                // ->toArray(), not the raw Collection: this app's
                // `database` cache store corrupts a cached value that
                // still has real objects (Models, Carbon dates) nested
                // inside it on the next read — confirmed live, see
                // CatalogController's/HeroSlideController's doc
                // comments for the full story. ->toArray() also
                // flattens every date attribute to a plain string.
                fn () => Game::query()->withCount('packages')->orderBy('name')->get()->toArray(),
            ));
        }

        $query = Game::query()->withCount('packages');

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($status) {
            $query->where('is_active', $status === 'active');
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function show(Game $game): JsonResponse
    {
        return response()->json($game->loadCount('packages'));
    }

    public function update(UpdateGameRequest $request, Game $game): JsonResponse
    {
        $game->update($request->validated());
        self::forgetIndexCache();

        return response()->json($game);
    }

    /**
     * Cascades to every Package under this Game (`packages.game_id`
     * is `cascadeOnDelete()`) — a real destructive action, the
     * frontend must confirm before calling this.
     */
    public function destroy(Game $game): JsonResponse
    {
        $game->delete();
        self::forgetIndexCache();
        self::forgetPackagesCache($game->id);

        return response()->json(null, 204);
    }

    /**
     * Powers the "Catalog" tab in Price Sync Stage 2's category-group
     * screen, and the Admin Games & Packages detail view — every
     * Package already promoted under this Game.
     *
     * `supplier_active` (founder revision, 2026-07-25): a read-only
     * indicator — has the supplier turned this item off on their own
     * side since it was promoted? Matches the legacy reference
     * system's "tidak aktif" badge, distinct from `is_active` (our own
     * on/off control). Display only for now; the actual reactivation
     * review workflow (SYNC-5/6) is deliberately deferred to the
     * dedicated Price Sync feature (docs/prd.md §14), not built here.
     */
    public function packages(Game $game): JsonResponse
    {
        $packages = Cache::remember(
            self::packagesCacheKey($game->id),
            self::CACHE_TTL_SECONDS,
            function () use ($game) {
                // ADR-034 follow-up (founder feedback, 2026-08-25):
                // smallest denomination first reads as cheapest-first
                // to an admin, and sorts numerically rather than the
                // previous alphabetical-by-name (which put "10209
                // Diamonds" before "1192 Diamonds"). Packages without
                // a curated denomination yet sort last, by name.
                $packages = $game->packages()
                    ->orderByRaw('denomination IS NULL')
                    ->orderBy('denomination')
                    ->orderBy('name')
                    ->get();

                $supplierStatuses = SupplierProduct::query()
                    ->whereIn('external_ref', $packages->pluck('supplier_package_ref'))
                    ->get(['supplier_id', 'external_ref', 'status_raw'])
                    ->keyBy(fn (SupplierProduct $p) => "{$p->supplier_id}:{$p->external_ref}");

                $packages->each(function (Package $package) use ($supplierStatuses) {
                    $status = $supplierStatuses->get("{$package->supplier_id}:{$package->supplier_package_ref}");
                    $package->supplier_active = $status?->status_raw === 'active';
                });

                // ->toArray(), not the raw Collection — same
                // database-cache-store corruption reason as index()
                // above.
                return $packages->toArray();
            },
        );

        return response()->json($packages);
    }

    /**
     * ADR-014 invalidation seam: called by GameController itself and
     * by PackageController/SupplierProductController — any write that
     * changes what index()/packages() return. Public + static rather
     * than an injected cache-service dependency: this is cheap,
     * stateless, and used from three different controllers, so a full
     * service class would be indirection with no second adapter to
     * justify it (codebase-design: "one adapter means a hypothetical
     * seam").
     */
    public static function forgetIndexCache(): void
    {
        Cache::forget('catalog.games.index');
        CatalogController::forgetIndexCache();
    }

    public static function forgetPackagesCache(int $gameId): void
    {
        Cache::forget(self::packagesCacheKey($gameId));
        CatalogController::forgetPackagesCache($gameId);
    }

    private static function packagesCacheKey(int $gameId): string
    {
        return "catalog.games.{$gameId}.packages";
    }
}
