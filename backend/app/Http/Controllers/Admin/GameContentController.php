<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\GameController;
use App\Http\Requests\Seo\UpdateGameContentRequest;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * ADR-140 decisions 6–7: the one writer of a game's content. What reaches the
 * storefront, and for whom, is decided in `Game::publishedContentFor`.
 */
class GameContentController extends Controller
{
    private const CONTENT_FIELDS = ['content_summary', 'content_about', 'content_faq'];

    public function update(UpdateGameContentRequest $request, Game $game): JsonResponse
    {
        $data = $request->validated();

        $game->fill([
            'content_summary' => $data['summary'] ?? null,
            'content_about' => $data['about'] ?? null,
            'content_faq' => array_values($data['faq'] ?? []) ?: null,
        ]);

        if ($data['published'] && ! $game->hasContent()) {
            throw ValidationException::withMessages(['published' => 'Add some content before publishing.']);
        }

        // An honest lastmod (ADR-140 decision 4): the date moves only when the game
        // is published, or when published content actually changes.
        if (! $data['published']) {
            $game->content_published_at = null;
        } elseif ($game->content_published_at === null || $game->isDirty(self::CONTENT_FIELDS)) {
            $game->content_published_at = now();
        }

        $game->save();

        // Same purge as GameSeoController::update: the catalog index (and the
        // storefront's Next cache with it) shows the summary.
        GameController::forgetIndexCache();
        CatalogController::forgetPackagesCache($game->id);

        return response()->json(array_merge($game->toArray(), ['content_status' => $game->contentStatus()]));
    }
}
