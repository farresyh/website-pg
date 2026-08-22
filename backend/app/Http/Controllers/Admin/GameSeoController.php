<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\GameController;
use App\Http\Requests\Seo\UpdateGameSeoRequest;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-029 decision 11: per-game SEO's own list+edit screen — data stays
 * on Game (decision 1), only the editing surface moved out of
 * EditGameModal into here, mirroring the codebase's existing list-page-
 * plus-edit pattern (/admin/vouchers, /admin/withdrawals).
 */
class GameSeoController extends Controller
{
    /**
     * `filter` — `complete`|`incomplete`|`missing` (SEO title AND
     * description both present/absent), omit for all.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Game::query()->select([
            'id', 'name', 'slug', 'is_active', 'seo_title', 'seo_description', 'seo_og_image', 'no_index',
        ]);

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $games = $query->orderBy('name')->get()->map(function (Game $game) {
            $complete = filled($game->seo_title) && filled($game->seo_description);
            $missing = blank($game->seo_title) && blank($game->seo_description);

            return array_merge($game->toArray(), [
                'seo_status' => $missing ? 'missing' : ($complete ? 'complete' : 'incomplete'),
            ]);
        });

        $filter = $request->query('filter');
        if (in_array($filter, ['complete', 'incomplete', 'missing'], true)) {
            $games = $games->filter(fn ($g) => $g['seo_status'] === $filter)->values();
        }

        return response()->json($games);
    }

    public function show(Game $game): JsonResponse
    {
        return response()->json($game);
    }

    public function update(UpdateGameSeoRequest $request, Game $game): JsonResponse
    {
        $game->update($request->validated());
        GameController::forgetIndexCache();
        CatalogController::forgetPackagesCache($game->id);

        return response()->json($game);
    }
}
