<?php

namespace App\Services\Sync;

use App\Models\Supplier;
use App\Models\SupplierProduct;
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
                    'price_sen' => $item->price !== null ? (int) round($item->price * 100) : null,
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
        );
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
