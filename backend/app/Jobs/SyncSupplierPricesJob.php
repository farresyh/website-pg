<?php

namespace App\Jobs;

use App\Models\PriceSyncRun;
use App\Models\Supplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * ADR-015 decision #5: one price-sync run, written onto its PriceSyncRun
 * so `/middleware/price-sync` can poll status instead of blocking on a
 * live supplier call. The per-supplier work (Stage 1, then price
 * propagation and deactivation) runs in SyncSupplierCatalogJob, one per
 * supplier (ADR-137 2026-11-05 decision 3). Same "thin trigger, real
 * work off-thread" shape as FulfillOrderJob (ADR-014).
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
     * ADR-031 decision 4: every active, syncable Supplier is synced with its
     * own adapter. ADR-137 2026-11-05 decision 3: each in its own
     * SyncSupplierCatalogJob, dispatched as one batch on this queue, so each
     * has its own time limit. One supplier's failure never blocks the rest.
     * The batch's `finally` sets the run's status (PriceSyncRun::finishBatch):
     * failed when no supplier synced, partial when some failed (ADR-015
     * 2026-10-17 addendum, `stats.failed_suppliers`), success otherwise.
     */
    public function handle(): void
    {
        $this->run->update(['status' => 'running', 'started_at' => now(), 'stats' => PriceSyncRun::emptyStats()]);

        // Only API suppliers that actually have credentials are syncable
        // (Supplier::isSyncable(); a manual supplier has no catalogue to
        // sync, ADR-125 decision 2). One with an empty api_config is
        // skipped, not attempted-then-caught, so an environment with no
        // configured supplier yet is a clean no-op 'success'.
        $allActive = Supplier::query()->where('is_active', true)->get();
        $suppliers = $allActive->filter(fn (Supplier $supplier) => $supplier->isSyncable())->values();

        if ($allActive->count() !== $suppliers->count()) {
            Log::info('SyncSupplierPricesJob: skipping supplier(s) that are manual or have no api_config', [
                'run_id' => $this->run->id,
                'skipped' => $allActive->count() - $suppliers->count(),
            ]);
        }

        if ($suppliers->isEmpty()) {
            $this->run->update(['status' => 'success', 'finished_at' => now()]);

            return;
        }

        $runId = $this->run->id;
        $supplierCount = $suppliers->count();

        Bus::batch($suppliers->map(fn (Supplier $supplier) => new SyncSupplierCatalogJob($this->run, $supplier))->all())
            ->name("price-sync-run-{$runId}")
            ->onQueue('price-sync')
            ->allowFailures()
            ->finally(fn () => PriceSyncRun::query()->find($runId)?->finishBatch($supplierCount))
            ->dispatch();
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
    public function failed(?\Throwable $exception): void
    {
        $this->run->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error_message' => $exception?->getMessage() ?? 'Job failed without an exception message.',
        ]);
    }
}
