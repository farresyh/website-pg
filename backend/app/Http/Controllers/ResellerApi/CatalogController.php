<?php

namespace App\Http\Controllers\ResellerApi;

use App\Exceptions\ResellerApi\ResellerApiException;
use App\Models\Game;
use App\Models\Package;
use App\Services\Pricing\OrderPricingResolver;
use App\Services\Reseller\ResellerCatalogService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-074 decision 3 + ADR-084 PR-1: `GET /api/reseller/v1/catalog` — the
 * price list for the calling `Reseller`'s own assigned tier, grouped by
 * game so an integrator gets a game name and code rather than a flat list
 * of `{reseller_code}-{denomination}` strings it has to parse.
 *
 * `ResellerCatalogService::listAvailable()` (cached, tier-independent)
 * resolves the catalogue; this controller prices each row at the caller's
 * tier and shapes it. Only the caller's own price is ever shown — never
 * another tier's, never `markup_percent`, never cost (ADR-084 decision 2).
 */
class CatalogController extends Controller
{
    /**
     * A1 hardening (2026-09-10 reseller-family audit, `docs/build-log.md`):
     * `packagesFor()`'s per-row `resolveResellerWallet()` compute used to
     * re-run on every call regardless of caller — pure CPU (no DB query),
     * but ~486 rows re-priced per request. Tagged (not a single key) so
     * every tier's entry can be flushed at once, from either catalog-write
     * choke point: `Admin\CatalogController::forgetIndexCache()` (a
     * package/game changed) or `Admin\ResellerTierController::update()`
     * (a tier's own `markup_percent` changed — the catalog didn't).
     */
    public const PRICED_CACHE_TAG = 'reseller.catalog.priced';

    public function __construct(
        private readonly ResellerCatalogService $catalog,
        private readonly OrderPricingResolver $pricingResolver,
    ) {}

    public static function forgetPricedCache(): void
    {
        Cache::tags([self::PRICED_CACHE_TAG])->flush();
    }

    #[Endpoint(
        title: 'List the catalogue',
        description: "Every orderable product for the calling reseller, grouped by game. Each package's `price_sen` is your own price for that package, in sen. Order against `packages[].code` (`{game code}-{denomination}`).",
    )]
    #[Response(status: 200, description: 'Your priced catalogue.', examples: [[
        'games' => [[
            'code' => 'MLMY',
            'name' => 'Mobile Legends (Malaysia)',
            'checkout_input' => ['field' => 'zone_id', 'options' => ['SouthEastAsia', 'MENA']],
            'packages' => [
                ['code' => 'MLMY-14', 'name' => '14 Diamonds', 'price_sen' => 1200],
                ['code' => 'MLMY-86', 'name' => '86 Diamonds', 'price_sen' => 6300],
            ],
        ]],
    ]])]
    #[Response(status: 401, description: '`MISSING_API_KEY` or `INVALID_API_KEY`.', type: self::ERROR_SHAPE, examples: [self::ERROR_401])]
    #[Response(status: 403, description: '`RESELLER_INACTIVE` or `IP_NOT_ALLOWED`.', type: self::ERROR_SHAPE, examples: [self::ERROR_403])]
    #[Response(status: 422, description: '`NO_TIER_ASSIGNED` — this account has no pricing configured yet.', type: self::ERROR_SHAPE, examples: [[
        'error' => 'NO_TIER_ASSIGNED', 'message' => 'This reseller account has no pricing configured yet. Contact PekanGame to set it up.',
    ]])]
    #[Response(status: 429, description: '`RATE_LIMITED` — retry after the `Retry-After` header.', type: self::ERROR_SHAPE, examples: [self::ERROR_429])]
    public function index(Request $request): JsonResponse
    {
        $reseller = $this->reseller($request);

        if ($reseller->reseller_tier_id === null) {
            throw ResellerApiException::noTierAssigned();
        }

        $payload = $this->pricedCatalogForTier($reseller->reseller_tier_id, (float) $reseller->tier->markup_percent);

        return response()->json(['games' => $payload]);
    }

    /**
     * @return array<int, array{code: string, name: string, checkout_input: array{field: ?string, options: ?array<int, string>}, packages: array<int, array{code: string, name: string, price_sen: int}>}>
     */
    private function pricedCatalogForTier(int $tierId, float $markupPercent): array
    {
        return Cache::tags([self::PRICED_CACHE_TAG])->remember(
            self::PRICED_CACHE_TAG.".{$tierId}",
            60,
            function () use ($markupPercent): array {
                $rows = $this->catalog->listAvailable();
                $rowsByGameId = $rows->groupBy(fn (array $row): int => $row['package']->game_id);

                // ADR-097 decision 12/17 — 'validation_rules' must be in
                // this explicit column list or checkout_input silently
                // resolves to null for every row.
                $games = Game::query()
                    ->whereIn('id', $rowsByGameId->keys())
                    ->orderBy('name')
                    ->get(['id', 'reseller_code', 'name', 'validation_rules']);

                return $games->map(fn (Game $game): array => [
                    'code' => $game->reseller_code,
                    'name' => $game->name,
                    'checkout_input' => [
                        'field' => $game->validation_rules['extra_field'] ?? null,
                        'options' => $game->zoneOptions(),
                    ],
                    'packages' => $this->packagesFor($rowsByGameId->get($game->id, collect()), $markupPercent),
                ])->values()->all();
            },
        );
    }

    /**
     * ADR-060 PR-4b: prices through `OrderPricingResolver::resolveResellerWallet()`
     * — the same seam `ResellerOrderPlacementService` charges through, so the
     * quoted price and the debited price can never drift.
     *
     * @param  Collection<int, array{code: string, package: Package}>  $rows
     * @return array<int, array{code: string, name: string, price_sen: int}>
     */
    private function packagesFor(Collection $rows, float $markupPercent): array
    {
        return $rows->map(fn (array $row): array => [
            'code' => $row['code'],
            'name' => $row['package']->name,
            'price_sen' => (int) $this->pricingResolver->resolveResellerWallet(
                $row['package']->cost_price,
                $row['package']->standard_selling_price,
                $markupPercent,
            )->sellingPriceSen,
        ])->values()->all();
    }
}
