<?php

namespace App\Jobs;

use App\Http\Controllers\GameController;
use App\Models\PriceSyncRun;
use App\Models\Supplier;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Sync\PackagePriceSyncService;
use App\Services\Sync\PendingReactivationAutoApprover;
use App\Services\Sync\ProductSyncService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR-137 2026-11-05 decision 3: one supplier's share of a price-sync run,
 * in its own job, so each supplier has its own time limit and a slow one
 * (Gamevion's paced catalog) can never cut another short. Dispatched as a
 * batch by SyncSupplierPricesJob on the same `price-sync` queue.
 *
 * Stage 1 (ProductSyncService), then price propagation and deactivation
 * (PackagePriceSyncService), then the best-effort auto-approver (ADR-100),
 * exactly as the single job ran them per supplier before.
 */
final class SyncSupplierCatalogJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public function __construct(
        public readonly PriceSyncRun $run,
        public readonly Supplier $supplier,
    ) {
        $this->onQueue('price-sync');
    }

    public function handle(ProductSyncService $productSync, PackagePriceSyncService $packageSync, SupplierAdapterFactory $supplierAdapters, PendingReactivationAutoApprover $autoApprover): void
    {
        try {
            $adapter = $supplierAdapters->for($this->supplier);
            $catalog = $productSync->sync($this->supplier, $adapter);
            $packages = $packageSync->apply($this->supplier, $catalog->syncedAt, $this->run->id);
        } catch (Throwable $e) {
            Log::error('SyncSupplierCatalogJob: supplier sync failed', [
                'run_id' => $this->run->id,
                'supplier_slug' => $this->supplier->slug,
                'exception' => $e->getMessage(),
            ]);
            $this->run->recordSupplierFailed($this->supplier->slug, $e->getMessage());

            return;
        }

        // ADR-015 2026-10-17 addendum: CheckPriceSyncStalenessCommand alerts on this.
        $this->supplier->update(['last_price_sync_at' => now()]);
        $this->run->recordSupplierSynced($this->supplier->slug, $catalog, $packages);

        $affectedGameIds = array_unique($packages->affectedGameIds);

        foreach ($affectedGameIds as $gameId) {
            GameController::forgetPackagesCache($gameId, false);
        }

        if ($affectedGameIds !== []) {
            GameController::forgetIndexCache();
        }

        // ADR-100: best-effort automation on top of a sync that already
        // succeeded, so its failure never turns this supplier into a failure.
        try {
            $autoApprover->run($this->supplier, $this->run->id);
        } catch (Throwable $e) {
            Log::error('SyncSupplierCatalogJob: PendingReactivationAutoApprover failed', [
                'run_id' => $this->run->id,
                'supplier_slug' => $this->supplier->slug,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /** `$tries = 1`: a timeout or crash lands here and is listed on the run. */
    public function failed(?Throwable $exception): void
    {
        $this->run->recordSupplierFailed($this->supplier->slug, $exception?->getMessage() ?? 'The supplier sync job failed without a message.');
    }
}
