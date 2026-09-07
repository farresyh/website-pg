<?php

namespace App\Services\Reseller;

use App\Models\Game;
use App\Models\Package;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-075's catalog-code addendum (2026-09-04), decision 7: resolves
 * a reseller-facing public product code (`{reseller_code}-
 * {denomination-or-catalog_code}`, e.g. `MLMY-14`, `MLMY-P1`) back to
 * the current cheapest active `Package` — the one seam both the
 * Reseller API (ADR-074, PR-E) and Reseller Bot (ADR-075, PR-F)
 * channels call, neither of which exists yet in this PR. Sits
 * alongside the already-shipped `ResellerOrderPlacementService`
 * (PR-D, same namespace): this service resolves the catalog lookup
 * only — it returns the raw `Package`, never a price. Pricing
 * (`PricingService::calculateForAffiliate()` against the caller's
 * `Reseller` tier) is the caller's own responsibility, mirroring
 * `ResellerOrderPlacementService`'s DTO contract where the caller
 * resolves price inputs before calling in.
 *
 * `Package.id`/`supplier_package_ref` are never exposed to either
 * channel — only this public code is, matching the storefront's own
 * narrow-response-shape discipline (`backend/AGENTS.md`).
 */
final class ResellerCatalogService
{
    /**
     * Splits on the first `-` — `games.reseller_code` is restricted to
     * `[A-Z]{2,10}` (no dash), so the game segment can never contain
     * one. The trailing segment resolves via `denomination` when it's
     * all-digit, otherwise via `catalog_code` (which is required to
     * contain at least one letter — UpdatePackageCatalogCodeRequest —
     * so the two never collide at parse time either).
     */
    public function resolveByCode(string $code): ?Package
    {
        $normalized = strtoupper(trim($code));
        $parts = explode('-', $normalized, 2);

        if (count($parts) !== 2 || $parts[1] === '') {
            return null;
        }

        [$resellerCode, $rest] = $parts;

        $game = Game::query()->where('reseller_code', $resellerCode)->first();
        if ($game === null) {
            return null;
        }

        $denomination = ctype_digit($rest) ? (int) $rest : null;
        $catalogCode = $denomination === null ? $rest : null;

        return Package::cheapestActiveFor($game->id, $denomination, $catalogCode);
    }

    /**
     * Every orderable product across every reseller-coded Game, paired
     * with its resolved public code — the shared "price list" source
     * both the Reseller API's `GET /catalog` (ADR-074) and the future
     * Reseller Bot's `.list` command (ADR-075) build their response
     * from. A `Game` with no `reseller_code`, or with zero eligible
     * active packages, contributes nothing; a `Package` with neither
     * `denomination` nor `catalog_code` set (not yet curated) is
     * skipped — there's no valid code to give it.
     *
     * Returns raw `Package` rows, never `Package.id`/
     * `supplier_package_ref` — pricing (`PricingService::
     * calculateForAffiliate()` against the caller's own `Reseller`
     * tier) stays the caller's job, same division of responsibility as
     * `resolveByCode()` above.
     *
     * ADR-077 PR-5 (decision 10): this used to run one
     * `Package::cheapestActivePerGame()` query per reseller-coded Game
     * (~486 of them for the real catalogue) with no cache at all — the
     * one genuine N+1 in the read layer. Now: two queries total (games,
     * then all their packages in one `whereIn`), deduped per game in
     * PHP, and the shaped listing held in `Cache::remember` for 60s. The
     * cached value is a plain array (`backend/AGENTS.md`); the `Package`
     * rows are rehydrated from it on the way out so this method's
     * contract is unchanged for both channels. The listing is
     * tier-independent (pricing is the caller's job), so it is one
     * global key, invalidated at `CatalogController::forgetIndexCache()`
     * — the same choke point every catalog write already passes through.
     *
     * @return Collection<int, array{code: string, package: Package}>
     */
    public function listAvailable(): Collection
    {
        return collect($this->cachedListing())->map(fn (array $row): array => [
            'code' => $row['code'],
            'package' => (new Package)->forceFill($row['package'])->syncOriginal(),
        ]);
    }

    public const CACHE_KEY = 'reseller.catalog.available';

    /**
     * @return array<int, array{code: string, package: array<string, mixed>}>
     */
    private function cachedListing(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, function (): array {
            $games = Game::query()
                ->whereNotNull('reseller_code')
                ->where('is_active', true)
                ->get(['id', 'reseller_code']);

            $packagesByGame = Package::query()
                ->whereIn('game_id', $games->pluck('id'))
                ->where('is_active', true)
                ->get()
                ->groupBy('game_id');

            return $games->flatMap(fn (Game $game) => Package::dedupeActivePerGame($packagesByGame->get($game->id, collect()))
                ->filter(fn (Package $package) => $package->denomination !== null || $package->catalog_code !== null)
                ->map(fn (Package $package): array => [
                    'code' => $game->reseller_code.'-'.($package->denomination ?? $package->catalog_code),
                    'package' => $package->only([
                        'id', 'game_id', 'name', 'denomination', 'catalog_code',
                        'cost_price', 'standard_selling_price',
                    ]),
                ])
                ->values())
                ->values()
                ->all();
        });
    }
}
