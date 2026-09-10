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
    public function __construct(
        private readonly ResellerCatalogService $catalog,
        private readonly OrderPricingResolver $pricingResolver,
    ) {}

    #[Endpoint(
        title: 'List the catalogue',
        description: "Every orderable product for the calling reseller, grouped by game. Each package's `price_sen` is the caller's own wallet-tier price in sen, markup already applied — never another tier's price, never cost. Order against `packages[].code` (`{game code}-{denomination}`).",
    )]
    #[Response(status: 200, description: 'The tier-priced catalogue.', examples: [[
        'games' => [[
            'code' => 'MLMY',
            'name' => 'Mobile Legends (Malaysia)',
            'packages' => [
                ['code' => 'MLMY-14', 'name' => '14 Diamonds', 'price_sen' => 1200],
                ['code' => 'MLMY-86', 'name' => '86 Diamonds', 'price_sen' => 6300],
            ],
        ]],
    ]])]
    #[Response(status: 401, description: '`MISSING_API_KEY` or `INVALID_API_KEY`.', type: self::ERROR_SHAPE, examples: [self::ERROR_401])]
    #[Response(status: 403, description: '`RESELLER_INACTIVE` or `IP_NOT_ALLOWED`.', type: self::ERROR_SHAPE, examples: [self::ERROR_403])]
    #[Response(status: 422, description: '`NO_TIER_ASSIGNED` — no wallet tier is set on this account.', type: self::ERROR_SHAPE, examples: [[
        'error' => 'NO_TIER_ASSIGNED', 'message' => 'This reseller account has no wallet tier assigned. Contact us to set one.',
    ]])]
    #[Response(status: 429, description: '`RATE_LIMITED` — retry after the `Retry-After` header.', type: self::ERROR_SHAPE, examples: [self::ERROR_429])]
    public function index(Request $request): JsonResponse
    {
        $reseller = $this->reseller($request);

        if ($reseller->reseller_tier_id === null) {
            throw ResellerApiException::noTierAssigned();
        }

        $markupPercent = (float) $reseller->tier->markup_percent;
        $rows = $this->catalog->listAvailable();

        $rowsByGameId = $rows->groupBy(fn (array $row): int => $row['package']->game_id);

        $games = Game::query()
            ->whereIn('id', $rowsByGameId->keys())
            ->orderBy('name')
            ->get(['id', 'reseller_code', 'name']);

        $payload = $games->map(fn (Game $game): array => [
            'code' => $game->reseller_code,
            'name' => $game->name,
            'packages' => $this->packagesFor($rowsByGameId->get($game->id, collect()), $markupPercent),
        ])->values();

        return response()->json(['games' => $payload]);
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
