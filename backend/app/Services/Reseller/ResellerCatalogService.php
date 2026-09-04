<?php

namespace App\Services\Reseller;

use App\Models\Game;
use App\Models\Package;

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
}
