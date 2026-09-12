<?php

namespace App\Services\Reseller;

use App\Models\Game;
use App\Models\ResellerTier;
use App\Services\Pricing\PricingService;

/**
 * ADR-091: the public "Reseller Price List" page's own data — a sales/
 * acquisition surface for prospective resellers, shown only on the
 * primary storefront and any `is_owned` affiliate brand (the caller's
 * job to check, via `StorefrontBrand`; this service doesn't know about
 * brands at all — the content is platform-wide, identical everywhere
 * it's allowed to render).
 *
 * Deliberately thin: reuses `ResellerCatalogService::listAvailable()`
 * as-is for "which games/packages" (same 60s cache, same reseller-code
 * eligibility/dedup rules the Reseller API/Bot channels already use —
 * no new cache key, no new invalidation choke point to keep in sync)
 * and `PricingService::calculateForAffiliate()` for "what does this
 * tier actually pay" (the exact same formula `OrderPricingResolver::
 * resolveResellerWallet()` uses at real order time, so a displayed
 * price can never drift from what a reseller in that tier is actually
 * charged). The per-tier arithmetic itself is cheap enough (a few
 * hundred packages × ≤3 tiers) to run fresh on every request rather
 * than adding a second cache layer that would need its own
 * invalidation on every `reseller_tiers` write.
 */
final class PublicResellerPriceListService
{
    public function __construct(
        private readonly ResellerCatalogService $catalog,
        private readonly PricingService $pricing,
    ) {}

    /**
     * @return array{tiers: array<int, array{id: int, name: string}>, games: array<int, array{game_name: string, package_name: string, prices: array<int, array{tier_id: int, price_sen: int}>}>}
     */
    public function build(): array
    {
        $tiers = ResellerTier::query()
            ->where('show_on_price_list', true)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'markup_percent']);

        // Same empty-array-means-off contract as MembershipController::
        // plans() — the caller (frontend) treats an empty `tiers` array
        // as "no page", no separate enabled flag to keep in sync with it.
        if ($tiers->isEmpty()) {
            return ['tiers' => [], 'games' => []];
        }

        $rows = $this->catalog->listAvailable();

        $gameIds = $rows->map(fn (array $row) => $row['package']->game_id)->unique();
        $gameNames = Game::query()->whereIn('id', $gameIds)->pluck('name', 'id');

        $games = $rows
            ->map(function (array $row) use ($tiers, $gameNames) {
                $package = $row['package'];

                return [
                    'game_name' => $gameNames[$package->game_id] ?? 'Unknown',
                    'package_name' => $package->name,
                    'prices' => $tiers
                        ->map(fn (ResellerTier $tier) => [
                            'tier_id' => $tier->id,
                            'price_sen' => $this->pricing->calculateForAffiliate(
                                $package->cost_price,
                                $package->standard_selling_price,
                                (float) $tier->markup_percent,
                                0.0,
                            )->sellingPrice,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        return [
            'tiers' => $tiers->map(fn (ResellerTier $tier) => ['id' => $tier->id, 'name' => $tier->name])->values()->all(),
            'games' => $games,
        ];
    }
}
