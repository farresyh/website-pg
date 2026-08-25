<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Http\Controllers\GameController;
use App\Http\Requests\Middleware\LinkSupplierProductCategoryRequest;
use App\Http\Requests\Middleware\PromoteSupplierProductRequest;
use App\Models\Game;
use App\Models\Package;
use App\Models\SupplierProduct;
use App\Services\Pricing\PackageMarkupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * MID-1..6/SUPP-3: browse the raw catalog Stage 1 sync mirrored
 * (`supplier_products`), link a whole `category_raw` group to a Game
 * once, then promote individual rows into real, customer-facing
 * `Package`s under it. See docs/prd.md §14's Price Sync Stage 2 note
 * for why this lives under /middleware, not /admin — and why the
 * category-group step exists at all (founder feedback: re-picking a
 * Game for all 316 items one-by-one doesn't scale).
 */
class SupplierProductController extends Controller
{
    /**
     * One row per distinct `category_raw` — this is the group-level
     * view an admin lands on first (analogous to legacy's Games list).
     * `game_id` reflects whatever any row in the group was last linked
     * to (LinkSupplierProductCategoryRequest stamps every row in the
     * group uniformly, so this is consistent unless a later Stage 1
     * sync adds a brand-new item to an already-linked category before
     * anyone re-links it — a known, accepted gap, not a bug).
     */
    public function categories(Request $request): JsonResponse
    {
        $query = SupplierProduct::query();

        if ($search = $request->query('search')) {
            $query->where('category_raw', 'like', "%{$search}%");
        }

        $products = $query->get(['category_raw', 'external_ref', 'game_id']);
        $promotedRefs = Package::query()->pluck('supplier_package_ref')->all();

        $categories = $products
            ->groupBy(fn (SupplierProduct $p) => $p->category_raw ?? '')
            ->map(function ($group, string $categoryRaw) use ($promotedRefs) {
                return [
                    'category_raw' => $categoryRaw !== '' ? $categoryRaw : null,
                    'total' => $group->count(),
                    'promoted_count' => $group->filter(
                        fn (SupplierProduct $p) => in_array($p->external_ref, $promotedRefs, true),
                    )->count(),
                    'game_id' => $group->pluck('game_id')->filter()->first(),
                ];
            })
            ->values();

        $games = Game::query()
            ->whereIn('id', $categories->pluck('game_id')->filter()->unique())
            ->get(['id', 'name', 'validation_rules'])
            ->keyBy('id');

        $categories = $categories
            ->map(function (array $c) use ($games) {
                $c['game'] = $c['game_id'] ? $games->get($c['game_id']) : null;

                return $c;
            })
            ->sortBy('category_raw')
            ->values();

        return response()->json($categories);
    }

    /**
     * Links every raw item sharing one `category_raw` to a Game in one
     * action. Safe to call again later (e.g. after Stage 1 re-syncs
     * new items into an already-linked category) — always re-stamps
     * the whole group, not just unlinked rows.
     *
     * `validation_rules` is stamped onto the Game here too (existing
     * or newly-created) — re-linking is the supported way to correct
     * it later, same "always re-stamps, safe to repeat" idempotency
     * already documented for `game_id` above.
     */
    public function linkCategory(LinkSupplierProductCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $game = isset($data['game_id'])
            ? Game::query()->findOrFail($data['game_id'])
            : Game::query()->create([
                'name' => $data['new_game']['name'],
                'slug' => $this->uniqueSlug($data['new_game']['name']),
                'category' => $data['new_game']['category'] ?? null,
            ]);

        $game->update(['validation_rules' => $data['validation_rules'] ?? null]);

        SupplierProduct::query()
            ->where('category_raw', $data['category_raw'])
            ->update(['game_id' => $game->id]);

        return response()->json(['game' => $game]);
    }

    /**
     * `is_promoted` is computed by matching `supplier_package_ref`
     * against already-created Packages — this table has no direct FK
     * to `packages` (a raw mirror row is disposable/replaceable by the
     * next sync; a Package is real inventory that outlives it).
     */
    public function index(Request $request): JsonResponse
    {
        $query = SupplierProduct::query()->with('supplier');

        if ($search = $request->query('search')) {
            // Gamevion's raw item names are pure denominations ("100
            // Diamonds") — the game identity lives in category_raw
            // ("Free Fire Global"), not the name. Search both, or
            // typing a game name finds nothing.
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('category_raw', 'like', "%{$search}%");
            });
        }

        if ($category = $request->query('category')) {
            $query->where('category_raw', $category);
        }

        $products = $query->orderBy('name')->paginate(50);

        $promotedRefs = Package::query()
            ->whereIn('supplier_package_ref', $products->pluck('external_ref'))
            ->pluck('supplier_package_ref')
            ->all();

        $products->getCollection()->transform(function (SupplierProduct $product) use ($promotedRefs) {
            $product->is_promoted = in_array($product->external_ref, $promotedRefs, true);

            return $product;
        });

        return response()->json($products);
    }

    /**
     * `game_id` is always required and always an existing Game — the
     * "which Game" decision was already made once for this item's
     * whole category (linkCategory), not here. Markup is never set
     * here either (founder revision, docs/prd.md §14) — every
     * promoted Package gets the configured default markup applied;
     * admin sets the real per-package markup afterward in
     * /admin/games (PackageController::updateMarkup).
     */
    public function promote(
        PromoteSupplierProductRequest $request,
        SupplierProduct $supplierProduct,
        PackageMarkupService $markup,
    ): JsonResponse {
        if ($supplierProduct->price_sen === null) {
            throw ValidationException::withMessages([
                'supplier_product' => ['This item has no price from the supplier yet — cannot promote.'],
            ]);
        }

        // ADR-025 decision #1: cost_price is never legitimately zero
        // or negative — the same floor check propagatePrice()'s own
        // sync path enforces, applied here too so every write site to
        // Package.cost_price agrees, not just the ones an admin
        // happens to notice.
        if ($supplierProduct->price_sen <= 0) {
            throw ValidationException::withMessages([
                'supplier_product' => ['This item\'s supplier price is invalid (zero or negative) — cannot promote.'],
            ]);
        }

        // Discovered live 2026-07-25: nothing previously stopped this
        // same raw item from being promoted twice, creating two
        // Packages selling identical inventory (the Product Manager
        // frontend only hides the "Add Again" button once promoted —
        // a client-side convenience, not a real guard). The unique
        // index on (supplier_id, supplier_package_ref) is the actual
        // guarantee; this check just turns a race into a friendly
        // message instead of a raw SQL constraint-violation 500.
        if (Package::query()->where('supplier_id', $supplierProduct->supplier_id)
            ->where('supplier_package_ref', $supplierProduct->external_ref)
            ->exists()) {
            throw ValidationException::withMessages([
                'supplier_product' => ['This item has already been promoted to a Package — edit the existing Package instead of promoting it again.'],
            ]);
        }

        $data = $request->validated();
        $markupPercent = (float) config('packages.default_markup_percent');

        $package = Package::query()->create([
            'game_id' => $data['game_id'],
            'name' => $data['name'],
            'denomination' => $data['denomination'] ?? null,
            'cost_price' => $supplierProduct->price_sen,
            'markup_percent' => $markupPercent,
            'reseller_cost_price' => $markup->calculateResellerCostPrice($supplierProduct->price_sen, $markupPercent),
            'supplier_id' => $supplierProduct->supplier_id,
            'supplier_package_ref' => $supplierProduct->external_ref,
        ]);

        // ADR-014: a promoted item is a new row in both GameController's
        // index() (packages_count) and packages($game) listings.
        GameController::forgetIndexCache();
        GameController::forgetPackagesCache($data['game_id']);

        return response()->json(['package' => $package], 201);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (Game::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
