<?php

namespace App\Jobs;

use App\Http\Controllers\GameController;
use App\Models\PriceSyncRun;
use App\Models\Supplier;
use App\Services\Sync\PackagePriceSyncService;
use App\Services\Sync\ProductSyncService;
use App\Services\Supplier\SupplierAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR-015 decision #5: orchestrates Price Sync's Stage 1
 * (ProductSyncService, unchanged) followed by PackagePriceSyncService
 * (price propagation + deactivation detection — no stage number of
 * its own, see that class's own docblock for why), writing the
 * outcome onto the given PriceSyncRun so `/middleware/price-sync` can
 * poll status instead of the admin panel blocking on a live Gamevion
 * call. Same "thin trigger, real work off-thread" shape as
 * FulfillOrderJob (ADR-014).
 *
 * A failure here (Gamevion timeout, adapter error) is not retried —
 * unlike FulfillOrderJob, nothing time-sensitive is waiting on this
 * job succeeding; the admin (or the next scheduled tick, once real
 * cron infra exists) simply triggers it again.
 */
final class SyncSupplierPricesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public function __construct(
        public readonly PriceSyncRun $run,
    ) {
    }

    public function handle(ProductSyncService $productSync, PackagePriceSyncService $packageSync, SupplierAdapter $adapter): void
    {
        $this->run->update(['status' => 'running', 'started_at' => now()]);

        // Hardcoded to the single Gamevion binding — same deliberate
        // placeholder as SyncSupplierProductsCommand, becomes a loop
        // over active suppliers once a per-Supplier adapter factory
        // exists (AppServiceProvider's own admitted gap).
        $supplier = Supplier::query()->firstOrCreate(
            ['slug' => 'gamevion'],
            ['name' => 'Gamevion', 'api_config' => [], 'currency' => 'MYR'],
        );

        try {
            $stage1 = $productSync->sync($supplier, $adapter);
            $stage2 = $packageSync->apply($supplier, $stage1->syncedAt, $this->run->id);
        } catch (Throwable $e) {
            $this->run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
            Log::error('SyncSupplierPricesJob failed', ['run_id' => $this->run->id, 'exception' => $e->getMessage()]);

            return;
        }

        foreach ($stage2->affectedGameIds as $gameId) {
            GameController::forgetPackagesCache($gameId);
        }

        if ($stage2->affectedGameIds !== []) {
            GameController::forgetIndexCache();
        }

        $this->run->update([
            'status' => 'success',
            'finished_at' => now(),
            'stats' => [
                'catalog_total' => $stage1->total,
                'catalog_created' => $stage1->created,
                'catalog_updated' => $stage1->updated,
                'price_changed' => $stage2->priceChanged,
                'deactivated' => $stage2->deactivated,
            ],
        ]);
    }
}
