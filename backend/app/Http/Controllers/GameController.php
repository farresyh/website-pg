<?php

namespace App\Http\Controllers;

use App\Http\Requests\Games\UpdateGameRequest;
use App\Models\Game;
use App\Models\Package;
use App\Models\SupplierProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * `status` — `active`/`inactive`, omit for all (GAME-2).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Game::query()->withCount('packages');

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($status = $request->query('status')) {
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
        $packages = $game->packages()->orderBy('name')->get();

        $supplierStatuses = SupplierProduct::query()
            ->whereIn('external_ref', $packages->pluck('supplier_package_ref'))
            ->get(['supplier_id', 'external_ref', 'status_raw'])
            ->keyBy(fn (SupplierProduct $p) => "{$p->supplier_id}:{$p->external_ref}");

        $packages->each(function (Package $package) use ($supplierStatuses) {
            $status = $supplierStatuses->get("{$package->supplier_id}:{$package->supplier_package_ref}");
            $package->supplier_active = $status?->status_raw === 'active';
        });

        return response()->json($packages);
    }
}
