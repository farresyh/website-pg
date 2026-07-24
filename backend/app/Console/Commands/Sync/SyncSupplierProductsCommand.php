<?php

namespace App\Console\Commands\Sync;

use App\Models\Supplier;
use App\Services\Sync\ProductSyncFailedException;
use App\Services\Sync\ProductSyncService;
use App\Services\Supplier\SupplierAdapter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SYNC-4 ("Sync All Prices Now") — Stage 1 only: mirrors the
 * currently-bound supplier's raw catalog into `supplier_products`
 * (MID-1/MID-6). Never touches `Package` — see ProductSyncService's
 * own docblock for why that's a deliberate, separate step.
 *
 * Hardcoded to the single Gamevion binding for now, matching
 * AppServiceProvider's own admitted placeholder (one global
 * SupplierAdapter, not yet a per-Supplier factory) — becomes a loop
 * over active suppliers once that factory exists, not a rewrite of
 * this command's logic.
 *
 * `Supplier::firstOrCreate` below is a deliberate stopgap: nothing
 * else in the codebase creates the Gamevion `Supplier` row yet
 * (SUPP-5's row in docs/prd.md §14 flags this same gap), and this
 * command needs one to attach `supplier_products.supplier_id` to.
 * Safe to call repeatedly — it only ever inserts a Supplier row keyed
 * by slug, never touches money/ledger state.
 */
#[Signature('app:sync-supplier-products')]
#[Description('Sync Gamevion\'s raw product catalog into the supplier_products staging table.')]
class SyncSupplierProductsCommand extends Command
{
    public function handle(ProductSyncService $service, SupplierAdapter $adapter): int
    {
        $supplier = Supplier::query()->firstOrCreate(
            ['slug' => 'gamevion'],
            ['name' => 'Gamevion', 'api_config' => [], 'currency' => 'MYR'],
        );

        $this->info("Syncing products for supplier '{$supplier->slug}'...");

        try {
            $result = $service->sync($supplier, $adapter);
        } catch (ProductSyncFailedException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Done in {$result->durationMs}ms — total: {$result->total}, created: {$result->created}, updated: {$result->updated}.");

        return self::SUCCESS;
    }
}
