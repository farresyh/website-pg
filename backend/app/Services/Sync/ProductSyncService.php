<?php

namespace App\Services\Sync;

use App\Models\Package;
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
    public function __construct(private readonly CurrencyRateService $currencyRates) {}

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
        $seenRefs = [];

        foreach ($items as $item) {
            $row = SupplierProduct::query()->updateOrCreate(
                [
                    'supplier_id' => $supplier->id,
                    'external_ref' => $item->productRef,
                ],
                [
                    'name' => $item->name ?? $item->productRef,
                    'category_raw' => $item->category,
                    // ADR-067 decision 4: the adapter sets groupLabel from
                    // whichever of its fields is the real "which game"
                    // identity; fall back to category when it has no
                    // opinion (keeps pre-ADR-067 Gamevion grouping intact
                    // even before its adapter was taught to set it).
                    'group_label' => $item->groupLabel ?? $item->category ?? '',
                    'type' => $item->type,
                    'price_sen' => $this->toMyrSen($item->price, $fxRateUsed['rate'] ?? null),
                    // ADR-069 decision 10 — the supplier's pre-conversion
                    // figure, display-only. Adapter-set, no branching here.
                    'raw_price' => $item->rawPrice,
                    'raw_currency' => $item->rawCurrency,
                    'status_raw' => $item->status,
                    'last_synced_at' => $syncedAt,
                ],
            );

            $row->wasRecentlyCreated ? $created++ : $updated++;
            $seenRefs[] = $item->productRef;
        }

        $pruned = $this->pruneVanishedRows($supplier, $seenRefs);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        return new ProductSyncResult(
            total: count($items),
            created: $created,
            updated: $updated,
            pruned: $pruned,
            durationMs: $durationMs,
            syncedAt: $syncedAt,
            fxRateUsed: $fxRateUsed,
        );
    }

    /**
     * ADR-067: a raw row this run did not see is one the supplier no
     * longer returns — a category now excluded by `category_whitelist`,
     * or a product removed from the Digiflazz buyer area. Delete it, so
     * a stale group stops lingering in Product Manager forever (a re-sync
     * never prunes on its own — `updateOrCreate` only ever adds/updates).
     *
     * Two guards keep this safe:
     *  - A **promoted** row is always KEPT. Its Package is real
     *    inventory, and PackagePriceSyncService (which runs right after
     *    this, in the same job) needs the row's now-stale
     *    `last_synced_at` to detect the vanished item and deactivate the
     *    Package the proper soft, logged way.
     *  - Nothing is pruned when `$seenRefs` is **empty**. `listProducts()`
     *    fails hard before the write loop on any adapter error, so a run
     *    that reaches here with zero items means either a genuinely empty
     *    catalog or a `category_whitelist` that matches nothing (a typo) —
     *    deleting every unpromoted row in either case is not worth the
     *    blast radius.
     *
     * @param  list<string>  $seenRefs  every external_ref written this run
     */
    private function pruneVanishedRows(Supplier $supplier, array $seenRefs): int
    {
        if ($seenRefs === []) {
            return 0;
        }

        $promotedRefs = Package::query()
            ->where('supplier_id', $supplier->id)
            ->pluck('supplier_package_ref')
            ->all();

        return SupplierProduct::query()
            ->where('supplier_id', $supplier->id)
            ->whereNotIn('external_ref', $seenRefs)
            ->whereNotIn('external_ref', $promotedRefs)
            ->delete();
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
