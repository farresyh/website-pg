<?php

namespace App\Jobs;

use App\Http\Controllers\GameController;
use App\Models\PriceSyncRun;
use App\Models\Supplier;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Sync\PackagePriceSyncService;
use App\Services\Sync\PendingReactivationAutoApprover;
use App\Services\Sync\ProductSyncService;
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
        // ADR-020 decision #5 — deliberately its own queue, separate
        // from FulfillOrderJob/ResendOrderDeliveryJob's `orders` queue:
        // this job can run long over many catalog items, and must never
        // delay an urgent, money-touching order job sitting behind it
        // in the same queue. onQueue(), not a redeclared $queue property
        // — see FulfillOrderJob's own constructor for why.
        $this->onQueue('price-sync');
    }

    /**
     * ADR-031 decision 4: loops every active Supplier, resolving each
     * one's own adapter via SupplierAdapterFactory — replaces the
     * earlier single hardcoded Gamevion binding. Stats aggregate
     * (summed) across every supplier that actually completed; one
     * supplier's failure (adapter unbound, or its own sync throwing)
     * is logged and skipped, never blocking the rest — mirrors
     * SyncSupplierProductsCommand's own per-supplier isolation. The
     * whole run is only marked 'failed' if every active supplier
     * failed; a partial success still records real, useful stats.
     */
    public function handle(ProductSyncService $productSync, PackagePriceSyncService $packageSync, SupplierAdapterFactory $supplierAdapters, PendingReactivationAutoApprover $autoApprover): void
    {
        $this->run->update(['status' => 'running', 'started_at' => now()]);

        // Only suppliers that actually have credentials are syncable — a
        // row whose api_config is still empty (created via /admin but not
        // yet filled, or a leftover from the pre-ADR-046 stopgap this job
        // used to create itself) can't be called and must not fail the
        // whole run. It is skipped here, not attempted-then-caught, so an
        // environment with no configured supplier yet is a clean no-op
        // 'success', not a 'failed' run every scheduled tick.
        $allActive = Supplier::query()->where('is_active', true)->get();
        $suppliers = $allActive->filter(fn (Supplier $supplier) => filled($supplier->api_config))->values();

        if ($allActive->count() !== $suppliers->count()) {
            Log::info('SyncSupplierPricesJob: skipping supplier(s) with no api_config', [
                'run_id' => $this->run->id,
                'skipped' => $allActive->count() - $suppliers->count(),
            ]);
        }

        $stats = [
            'catalog_total' => 0, 'catalog_created' => 0, 'catalog_updated' => 0, 'catalog_pruned' => 0,
            'price_changed' => 0, 'deactivated' => 0, 'floor_rejected' => 0, 'price_anomalies' => 0,
            // ADR-033 addendum decision 3: an array from day one — a
            // MYR-only run (Gamevion alone, today's only real case)
            // simply leaves this empty, not null/absent.
            'fx_rates_used' => [],
        ];
        $affectedGameIds = [];
        $succeeded = 0;
        $lastError = null;

        foreach ($suppliers as $supplier) {
            try {
                $adapter = $supplierAdapters->make($supplier->slug);
                $stage1 = $productSync->sync($supplier, $adapter);
                $stage2 = $packageSync->apply($supplier, $stage1->syncedAt, $this->run->id);
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                Log::error('SyncSupplierPricesJob: supplier sync failed', [
                    'run_id' => $this->run->id,
                    'supplier_slug' => $supplier->slug,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            $succeeded++;
            $stats['catalog_total'] += $stage1->total;
            $stats['catalog_created'] += $stage1->created;
            $stats['catalog_updated'] += $stage1->updated;
            $stats['catalog_pruned'] += $stage1->pruned;
            $stats['price_changed'] += $stage2->priceChanged;
            $stats['deactivated'] += $stage2->deactivated;
            $stats['floor_rejected'] += $stage2->floorRejected;
            $stats['price_anomalies'] += $stage2->anomaliesFlagged;

            if ($stage1->fxRateUsed !== null) {
                $stats['fx_rates_used'][] = $stage1->fxRateUsed;
            }

            $affectedGameIds = [...$affectedGameIds, ...$stage2->affectedGameIds];

            // ADR-100 — deliberately its OWN try/catch, separate from
            // stage 1/2's above: off by default and best-effort
            // automation layered on top of an already-successful sync,
            // so a failure here must never turn this supplier's
            // otherwise-successful run into a logged failure or (worse)
            // drop it out of $succeeded — sharing stage 1/2's catch
            // would do both.
            try {
                $autoApprover->run($supplier, $this->run->id);
            } catch (Throwable $e) {
                Log::error('SyncSupplierPricesJob: PendingReactivationAutoApprover failed', [
                    'run_id' => $this->run->id,
                    'supplier_slug' => $supplier->slug,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        if ($succeeded === 0 && $suppliers->isNotEmpty()) {
            $this->run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $lastError,
            ]);

            return;
        }

        $affectedGameIds = array_unique($affectedGameIds);

        foreach ($affectedGameIds as $gameId) {
            GameController::forgetPackagesCache($gameId, false);
        }

        if ($affectedGameIds !== []) {
            GameController::forgetIndexCache();
        }

        $this->run->update([
            'status' => 'success',
            'finished_at' => now(),
            'stats' => $stats,
        ]);
    }

    /**
     * `$tries = 1`, so an exception thrown outside handle()'s own
     * per-supplier try/catch (a worker timeout, OOM, or a bug in the
     * orchestration itself) goes straight here. Nothing else ever writes
     * a terminal status onto this run and there is no reconcile sweep for
     * PriceSyncRun the way there is for orders/payments — without this,
     * the row is stranded at 'running' forever and /middleware/price-sync
     * shows a permanent "Syncing…". Mark it failed with the reason.
     */
    public function failed(?Throwable $exception): void
    {
        $this->run->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error_message' => $exception?->getMessage() ?? 'Job failed without an exception message.',
        ]);
    }
}
