<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\GameController;
use App\Http\Requests\Seo\UpdateGameSeoRequest;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * ADR-029 decision 11: per-game SEO's own list+edit screen — data stays
 * on Game (decision 1), only the editing surface moved out of
 * EditGameModal into here, mirroring the codebase's existing list-page-
 * plus-edit pattern (/admin/vouchers, /admin/withdrawals).
 */
class GameSeoController extends Controller
{
    /**
     * `filter` — the content status (ADR-140 decision 8): `empty`|`draft`|`published`,
     * omit for all. The per-game SEO fields are exceptions and no longer count.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Game::query()->select([
            'id', 'name', 'slug', 'is_active', 'seo_title', 'seo_description', 'seo_og_image', 'no_index',
            'content_summary', 'content_about', 'content_faq', 'content_published_at',
        ]);

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $games = $query->orderBy('name')->get()->map(function (Game $game) {
            // The list carries the status and the publish date, never the bodies.
            return array_merge(Arr::except($game->toArray(), ['content_summary', 'content_about', 'content_faq']), [
                'content_status' => $game->contentStatus(),
            ]);
        });

        $filter = $request->query('filter');
        if (in_array($filter, ['empty', 'draft', 'published'], true)) {
            $games = $games->filter(fn ($g) => $g['content_status'] === $filter)->values();
        }

        return response()->json($games);
    }

    public function show(Game $game): JsonResponse
    {
        return response()->json(array_merge($game->toArray(), ['content_status' => $game->contentStatus()]));
    }

    public function update(UpdateGameSeoRequest $request, Game $game): JsonResponse
    {
        $game->update($request->validated());
        GameController::forgetIndexCache();
        CatalogController::forgetPackagesCache($game->id);

        return response()->json(array_merge($game->toArray(), ['content_status' => $game->contentStatus()]));
    }
}
