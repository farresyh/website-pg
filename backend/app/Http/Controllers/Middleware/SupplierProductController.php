<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Http\Controllers\GameController;
use App\Http\Requests\Middleware\LinkSupplierProductCategoryRequest;
use App\Http\Requests\Middleware\PromoteSupplierProductRequest;
use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Pricing\PackageMarkupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * MID-1..6/SUPP-3: browse the raw catalog Stage 1 sync mirrored
 * (`supplier_products`), link a whole `(supplier, group_label)` group
 * to a Game once, then promote individual rows into real,
 * customer-facing `Package`s under it. See docs/prd.md §14's Price
 * Sync Stage 2 note for why this lives under /middleware, not /admin —
 * and why the group step exists at all (founder feedback: re-picking a
 * Game for all 316 items one-by-one doesn't scale).
 *
 * ADR-067 decision 6: groups are keyed on `(supplier_id, group_label)`
 * — `group_label` is the adapter-set grouping string (Gamevion:
 * `category_raw`; Digiflazz: `brand`, because its `category` is a flat
 * "Games"), and a supplier column/filter distinguishes two suppliers'
 * near-identical groups (e.g. Gamevion "Mobile Legends" vs Digiflazz
 * "MOBILE LEGENDS") so the founder can link both to one Game and let
 * ADR-034's denomination dedup decide which package the storefront
 * shows.
 */
class SupplierProductController extends Controller
{
    /**
     * One row per distinct `(supplier_id, group_label)` — the
     * group-level view an admin lands on first. `game_id` reflects
     * whatever any row in the group was last linked to
     * (LinkSupplierProductCategoryRequest stamps every row in the group
     * uniformly, so this is consistent unless a later Stage 1 sync adds
     * a brand-new item to an already-linked group before anyone
     * re-links it — a known, accepted gap, not a bug).
     */
    public function categories(Request $request): JsonResponse
    {
        $query = SupplierProduct::query();

        if ($search = $request->query('search')) {
            $query->where('group_label', 'like', "%{$search}%");
        }

        $products = $query->get(['supplier_id', 'group_label', 'external_ref', 'game_id']);
        $promotedRefs = Package::query()->pluck('supplier_package_ref')->all();
        $suppliers = Supplier::query()->get(['id', 'slug', 'name'])->keyBy('id');

        $categories = $products
            ->groupBy(fn (SupplierProduct $p) => $p->supplier_id.'|'.$p->group_label)
            ->map(function ($group) use ($promotedRefs, $suppliers) {
                $first = $group->first();
                $supplier = $suppliers->get($first->supplier_id);

                return [
                    'supplier' => $supplier
                        ? ['id' => $supplier->id, 'slug' => $supplier->slug, 'name' => $supplier->name]
                        : null,
                    'group_label' => $first->group_label,
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
            ->sortBy(fn (array $c) => ($c['supplier']['name'] ?? '').'|'.$c['group_label'])
            ->values();

        return response()->json($categories);
    }

    /**
     * Links every raw item in one `(supplier_id, group_label)` group to
     * a Game in one action. Safe to call again later (e.g. after Stage
     * 1 re-syncs new items into an already-linked group) — always
     * re-stamps the whole group, not just unlinked rows. Scoped to one
     * supplier: linking Gamevion's "Mobile Legends" group never touches
     * Digiflazz's "MOBILE LEGENDS" group even though both point at the
     * same Game (ADR-067 decision 6).
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
            ->where('supplier_id', $data['supplier_id'])
            ->where('group_label', $data['group_label'])
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
            // A supplier's raw item names are often pure denominations
            // ("100 Diamonds") — the game identity lives in group_label
            // ("Free Fire Global"), not the name. Search both, or
            // typing a game name finds nothing.
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('group_label', 'like', "%{$search}%");
            });
        }

        // ADR-067 decision 6: a group is (supplier_id, group_label) —
        // both are required to scope down to one group's items.
        if ($supplierId = $request->query('supplier_id')) {
            $query->where('supplier_id', $supplierId);
        }

        if ($request->query('group_label') !== null) {
            $query->where('group_label', $request->query('group_label'));
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
            'standard_selling_price' => $markup->calculateStandardSellingPrice($supplierProduct->price_sen, $markupPercent),
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
