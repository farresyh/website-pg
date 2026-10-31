<?php

namespace App\Services\Reseller;

use App\Models\Game;
use App\Models\Package;
use App\Models\ResellerTier;
use App\Services\Pricing\PricingService;
use Illuminate\Support\Collection;

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
 *
 * ADR-091 2026-11-01 addendum: `build()` answers grouped (family → game →
 * package) from the display-only `games.family` / `games.region_label`;
 * `summary()` is the tiers alone, for the layout and sitemap that only ask
 * "is the page on" and must not pull every row on every page.
 */
final class PublicResellerPriceListService
{
    public function __construct(
        private readonly ResellerCatalogService $catalog,
        private readonly PricingService $pricing,
    ) {}

    /**
     * The tiers a visitor may see, in the page's own display order. Empty
     * means the page is off (same empty-array-means-off contract as
     * MembershipController::plans()).
     *
     * Admin's `sort_order` still governs every other tier-ordering surface
     * (Wallet tiers table, portal tier picker) unchanged: this one public
     * page renders it reversed (founder feedback 2026-09-13, cheapest tier
     * rightmost), a display-order flip only.
     *
     * @return Collection<int, ResellerTier>
     */
    private function tiers(): Collection
    {
        return ResellerTier::query()
            ->where('show_on_price_list', true)
            ->where('is_active', true)
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->get(['id', 'name', 'markup_percent']);
    }

    /**
     * @param  Collection<int, ResellerTier>  $tiers
     * @return list<array{id: int, name: string}>
     */
    private function tierList(Collection $tiers): array
    {
        return $tiers->map(fn (ResellerTier $tier) => ['id' => $tier->id, 'name' => $tier->name])->values()->all();
    }

    /**
     * @return array{tiers: list<array{id: int, name: string}>}
     */
    public function summary(): array
    {
        return ['tiers' => $this->tierList($this->tiers())];
    }

    /**
     * @return array{tiers: list<array{id: int, name: string}>, groups: list<array<string, mixed>>, apply_url?: string}
     */
    public function build(): array
    {
        $tiers = $this->tiers();

        if ($tiers->isEmpty()) {
            return ['tiers' => [], 'groups' => []];
        }

        $packagesByGame = $this->catalog->listAvailable()
            ->groupBy(fn (array $row) => $row['package']->game_id);

        // A fresh `Game` read, outside listAvailable()'s 60s cache, so an
        // admin's family edit needs no new invalidation.
        $games = Game::query()
            ->whereIn('id', $packagesByGame->keys())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'image_url', 'family', 'region_label']);

        $groups = [];
        foreach ($games as $game) {
            $key = $game->family !== null ? 'f:'.mb_strtolower($game->family) : 'g:'.$game->id;
            $groups[$key] ??= [
                'family' => $game->family ?? $game->name,
                'image_url' => $game->image_url,
                'games' => [],
            ];
            $groups[$key]['games'][] = [
                'name' => $game->name,
                'region' => $game->region_label ?? $game->name,
                'packages' => $packagesByGame[$game->id]
                    ->map(fn (array $row) => $this->packageRow($row['package'], $tiers))
                    ->values()
                    ->all(),
            ];
        }

        return [
            'tiers' => $this->tierList($tiers),
            'groups' => array_values($groups),
            // The application lives on the onboarding host (ADR-133); the
            // backend already knows it (ONBOARDING_URL), so the storefront
            // hardcodes no host.
            'apply_url' => rtrim((string) config('services.onboarding.url'), '/').'/apply',
        ];
    }

    /**
     * @param  Collection<int, ResellerTier>  $tiers
     * @return array{name: string, prices: list<array{tier_id: int, price_sen: int}>}
     */
    private function packageRow(Package $package, Collection $tiers): array
    {
        return [
            'name' => $package->name,
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
    }
}
