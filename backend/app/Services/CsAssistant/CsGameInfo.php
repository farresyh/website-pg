<?php

namespace App\Services\CsAssistant;

use App\Models\AffiliateGame;
use App\Models\Game;

/**
 * ADR-136 decision 17, the `game_info` tool, as changed by the 2026-11-03
 * PR 3a addendum: what the brand's own storefront shows about a game, with
 * the brand's own link, and no price. Prices are read on the page, so the
 * assistant never touches pricing.
 */
final class CsGameInfo
{
    /** @return array{games: list<array<string, string>>} */
    public function lookup(string $query, ?int $affiliateId, string $origin): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['games' => []];
        }

        $hidden = $affiliateId === null ? [] : AffiliateGame::hiddenGameIdsFor($affiliateId);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%';

        $games = Game::query()
            ->where('is_active', true)
            ->whereNotIn('id', $hidden)
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('slug', 'like', $like))
            ->orderBy('name')
            ->limit(3)
            ->get();

        return ['games' => $games->map(fn (Game $g) => [
            'name' => $g->name,
            'page' => "{$origin}/order/{$g->slug}",
            'description' => $this->plain($g->description, 500),
            'important_notes' => $this->plain($g->important_notes, 500),
            'delivery' => ($g->delivery_mode === 'manual' ? 'Manual delivery. ' : 'Instant delivery. ').$g->effectiveDeliverySubtext(),
        ])->values()->all()];
    }

    private function plain(?string $html, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $html))));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }
}
