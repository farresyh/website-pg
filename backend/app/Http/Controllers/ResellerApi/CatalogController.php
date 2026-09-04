<?php

namespace App\Http\Controllers\ResellerApi;

use App\Models\Package;
use App\Models\Reseller;
use App\Services\Pricing\PricingService;
use App\Services\Reseller\ResellerCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-074 decision 3: `GET /api/reseller/v1/catalog` — the price list
 * for the calling `Reseller`'s own assigned tier. Thin: `listAvailable()`
 * (shared with the future Bot channel) resolves the catalog, this
 * controller's only job is pricing each row at the caller's tier and
 * shaping the response.
 */
class CatalogController extends Controller
{
    public function __construct(
        private readonly ResellerCatalogService $catalog,
        private readonly PricingService $pricing,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $reseller = $this->reseller($request);

        if ($reseller->reseller_tier_id === null) {
            return response()->json(['message' => 'No wallet tier assigned to this reseller account.'], 422);
        }

        $items = $this->catalog->listAvailable()
            ->map(fn (array $row) => self::publicListing($row['code'], $row['package'], $reseller, $this->pricing))
            ->values();

        return response()->json(['items' => $items]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function publicListing(string $code, Package $package, Reseller $reseller, PricingService $pricing): array
    {
        $tierPricing = $pricing->calculateForAffiliate(
            $package->cost_price,
            $package->standard_selling_price,
            (float) $reseller->tier->markup_percent,
            0.0,
        );

        return [
            'code' => $code,
            'name' => $package->name,
            'price_sen' => $tierPricing->sellingPrice,
        ];
    }
}
