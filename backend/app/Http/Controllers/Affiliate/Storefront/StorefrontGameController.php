<?php

namespace App\Http\Controllers\Affiliate\Storefront;

use App\Http\Controllers\Affiliate\Concerns\AssertsAffiliateWritable;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Affiliate\Storefront\UpdateGameVisibilityRequest;
use App\Models\AffiliateGame;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * ADR-060 PR-6 — the Catalog tab. Every active platform game is visible
 * on a brand's storefront by default; the affiliate turns one fully off
 * (ADR-059 decision 3 — no per-package granularity, no admin approval).
 *
 * `affiliate_game` rows are filtered by the acting affiliate's id
 * explicitly (`withoutAffiliateScope()`), not via the `BelongsToAffiliate`
 * global scope — the portal controllers in this codebase all scope by
 * hand off `affiliateOwner()` (see `DomainController`/`ProfileController`).
 * A row's absence means visible.
 */
class StorefrontGameController extends Controller
{
    use AssertsAffiliateWritable;

    public function index(Request $request): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();

        $games = Game::query()
            ->where('is_active', true)
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'category']);

        $hidden = $this->hiddenGameIds($affiliate->id)->flip();

        return response()->json([
            'games' => $games->map(fn (Game $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'slug' => $g->slug,
                'category' => $g->category,
                'is_visible' => ! $hidden->has($g->id),
            ]),
            'writable' => $affiliate->status === 'active',
        ]);
    }

    public function update(UpdateGameVisibilityRequest $request, Game $game): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();
        $this->assertWritable($affiliate);
        abort_unless($game->is_active, 404);

        $isVisible = $request->boolean('is_visible');

        if (! $isVisible && $this->wouldHideLastVisibleGame($affiliate->id, $game->id)) {
            return response()->json([
                'message' => 'At least one game must stay visible on your storefront.',
            ], 422);
        }

        AffiliateGame::withoutAffiliateScope()->updateOrCreate(
            ['affiliate_id' => $affiliate->id, 'game_id' => $game->id],
            ['is_visible' => $isVisible],
        );

        // Visibility changes what the catalog listing shows and whether a
        // direct game/packages URL resolves for this brand — flush both.
        CatalogController::forgetCacheForBrand($affiliate->id);

        return $this->index($request);
    }

    /**
     * True when `$gameId` is currently visible and it is the only
     * visible active game — turning it off would leave the storefront
     * with nothing to sell (planning addendum Q8).
     */
    private function wouldHideLastVisibleGame(int $affiliateId, int $gameId): bool
    {
        $activeIds = Game::query()->where('is_active', true)->pluck('id');
        $visibleIds = $activeIds->diff($this->hiddenGameIds($affiliateId));

        return $visibleIds->contains($gameId) && $visibleIds->count() <= 1;
    }

    /**
     * @return Collection<int, int>
     */
    private function hiddenGameIds(int $affiliateId): Collection
    {
        return AffiliateGame::withoutAffiliateScope()
            ->where('affiliate_id', $affiliateId)
            ->where('is_visible', false)
            ->pluck('game_id');
    }
}
