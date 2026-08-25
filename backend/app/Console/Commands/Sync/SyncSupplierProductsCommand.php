<?php

namespace App\Console\Commands\Sync;

use App\Models\Supplier;
use App\Services\Sync\ProductSyncFailedException;
use App\Services\Sync\ProductSyncService;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\UnsupportedSupplierException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SYNC-4 ("Sync All Prices Now") — Stage 1 only: mirrors every active
 * supplier's raw catalog into `supplier_products` (MID-1/MID-6).
 * Never touches `Package` — see ProductSyncService's own docblock for
 * why that's a deliberate, separate step.
 *
 * ADR-031 decision 4: loops active `Supplier` rows, resolving each
 * one's own adapter via SupplierAdapterFactory — replaces the earlier
 * single hardcoded Gamevion binding. `Supplier::firstOrCreate` below
 * is kept as the same deliberate stopgap it always was (nothing else
 * in the codebase creates the Gamevion `Supplier` row yet — SUPP-5's
 * row in docs/prd.md §14 flags this same gap) so a fresh install still
 * has at least one supplier row to sync; any additional supplier
 * (Digiflazz, ADR-030) is expected to already exist as a real row by
 * the time this command runs.
 *
 * A supplier whose adapter isn't bound (UnsupportedSupplierException)
 * or whose own sync fails is logged and skipped, not fatal to the
 * whole run — one broken supplier must never block every other
 * supplier's sync. Overall exit code is FAILURE only if every active
 * supplier failed.
 */
#[Signature('app:sync-supplier-products')]
#[Description('Sync every active supplier\'s raw product catalog into the supplier_products staging table.')]
class SyncSupplierProductsCommand extends Command
{
    public function handle(ProductSyncService $service, SupplierAdapterFactory $supplierAdapters): int
    {
        Supplier::query()->firstOrCreate(
            ['slug' => 'gamevion'],
            ['name' => 'Gamevion', 'api_config' => [], 'currency' => 'MYR'],
        );

        $suppliers = Supplier::query()->where('is_active', true)->get();
        $succeeded = 0;

        foreach ($suppliers as $supplier) {
            $this->info("Syncing products for supplier '{$supplier->slug}'...");

            try {
                $adapter = $supplierAdapters->make($supplier->slug);
                $result = $service->sync($supplier, $adapter);
            } catch (UnsupportedSupplierException|ProductSyncFailedException $e) {
                $this->error("Supplier '{$supplier->slug}' sync failed: {$e->getMessage()}");

                continue;
            }

            $succeeded++;
            $this->info("Done in {$result->durationMs}ms — total: {$result->total}, created: {$result->created}, updated: {$result->updated}.");
        }

        return $succeeded > 0 || $suppliers->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
