<?php

namespace App\Services\Sync;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Currency\CurrencyRateService;
use App\Services\Currency\CurrencyRateUnavailableException;
use App\Services\Supplier\SupplierAdapter;
use Illuminate\Support\Carbon;

/**
 * Stage 1 of Price Sync (MID-1/MID-6, SYNC-4): pulls a supplier's raw
 * catalog via its Adapter and mirrors it into `supplier_products`,
 * idempotently by (supplier_id, external_ref). Deliberately does not
 * touch `Package` — promoting a raw listing into real, customer-facing
 * inventory is a separate, explicit admin action (SUPP-3/MID-5), not
 * something a sync ever does automatically.
 */
final class ProductSyncService
{
    public function __construct(private readonly CurrencyRateService $currencyRates)
    {
    }

    public function sync(Supplier $supplier, SupplierAdapter $adapter): ProductSyncResult
    {
        $startedAt = microtime(true);

        $response = $adapter->listProducts();

        if (! $response->success) {
            throw new ProductSyncFailedException(
                "Supplier '{$supplier->slug}' product sync failed: [{$response->errorCode}] {$response->errorMessage}",
            );
        }

        $items = $this->applyCategoryWhitelist($supplier, $response->data);

        // ADR-033 decision 3: the single conversion boundary — every
        // downstream consumer (PackagePriceSyncService, PricingService,
        // catalog, checkout) stays MYR-sen and is untouched. A supplier
        // with no prior FX rate ever stored, on a day the live fetch
        // also fails, surfaces as the same ProductSyncFailedException
        // an adapter failure already produces — this run writes nothing,
        // rather than silently mis-pricing every item.
        $fxRateUsed = null;

        if ($supplier->currency !== 'MYR') {
            try {
                $rate = $this->currencyRates->rate($supplier->currency, 'MYR');
            } catch (CurrencyRateUnavailableException $e) {
                throw new ProductSyncFailedException(
                    "Supplier '{$supplier->slug}' product sync failed: {$e->getMessage()}",
                    previous: $e,
                );
            }

            $fxRateUsed = [
                'from' => $supplier->currency,
                'to' => 'MYR',
                'rate' => $rate,
                'source' => (string) config('services.fx_api.source', 'open.er-api.com'),
            ];
        }

        $syncedAt = Carbon::now();
        $created = 0;
        $updated = 0;

        foreach ($items as $item) {
            $row = SupplierProduct::query()->updateOrCreate(
                [
                    'supplier_id' => $supplier->id,
                    'external_ref' => $item->productRef,
                ],
                [
                    'name' => $item->name ?? $item->productRef,
                    'category_raw' => $item->category,
                    'price_sen' => $this->toMyrSen($item->price, $fxRateUsed['rate'] ?? null),
                    'status_raw' => $item->status,
                    'last_synced_at' => $syncedAt,
                ],
            );

            $row->wasRecentlyCreated ? $created++ : $updated++;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        return new ProductSyncResult(
            total: count($items),
            created: $created,
            updated: $updated,
            durationMs: $durationMs,
            syncedAt: $syncedAt,
            fxRateUsed: $fxRateUsed,
        );
    }

    /**
     * MYR: `round` (unchanged, pre-ADR-033 behavior — no conversion,
     * no margin-protection concern). Non-MYR: `ceil`, never `round` —
     * protects margin by construction (the founder's own decision),
     * e.g. 83.33 sen never rounds down to 83.
     */
    private function toMyrSen(?float $price, ?float $rate): ?int
    {
        if ($price === null) {
            return null;
        }

        return $rate === null
            ? (int) round($price * 100)
            : (int) ceil($price * $rate * 100);
    }

    /**
     * ADR-030 decision 2: the "games only" business filter — deliberately
     * lives here, not in any SupplierAdapter, so a protocol-faithful
     * adapter (Digiflazz's real catalog spans PLN/pulsa/hotel/etc., not
     * just games) never has to know this platform's own business
     * policy. No whitelist configured (Gamevion today: `api_config`
     * carries none) means no filtering — mirrors everything, unchanged
     * from this method's pre-ADR-030 behavior.
     *
     * @param  SupplierCatalogItem[]  $items
     * @return SupplierCatalogItem[]
     */
    private function applyCategoryWhitelist(Supplier $supplier, array $items): array
    {
        $whitelist = $supplier->api_config['category_whitelist'] ?? null;

        if (! is_array($whitelist) || $whitelist === []) {
            return $items;
        }

        return array_values(array_filter(
            $items,
            fn ($item) => in_array($item->category, $whitelist, true),
        ));
    }
}
