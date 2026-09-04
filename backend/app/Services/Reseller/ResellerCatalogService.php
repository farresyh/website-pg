<?php

namespace App\Services\Reseller;

use App\Models\Game;
use App\Models\Package;
use Illuminate\Support\Collection;

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
     * @return Collection<int, array{code: string, package: Package}>
     */
    public function listAvailable(): Collection
    {
        return Game::query()
            ->whereNotNull('reseller_code')
            ->where('is_active', true)
            ->get()
            ->flatMap(fn (Game $game) => Package::cheapestActivePerGame($game->id)
                ->filter(fn (Package $package) => $package->denomination !== null || $package->catalog_code !== null)
                ->map(fn (Package $package) => [
                    'code' => $game->reseller_code.'-'.($package->denomination ?? $package->catalog_code),
                    'package' => $package,
                ]))
            ->values();
    }
}
