<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SyncSupplierPricesJob;
use App\Models\Game;
use App\Models\Package;
use App\Models\PriceSyncRun;
use App\Models\Supplier;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierCatalogItem;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SyncSupplierPricesJobTest extends TestCase
{
    use RefreshDatabase;

    private function bindFakeAdapter(bool $success, array $items = [], string $slug = 'gamevion'): void
    {
        $this->app->bind("supplier-adapter.{$slug}", fn () => new class($success, $items) implements SupplierAdapter
        {
            public function __construct(
                private readonly bool $success,
                private readonly array $items,
            ) {
            }

            public function checkBalance(): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function listProducts(): SupplierResponse
            {
                return $this->success
                    ? SupplierResponse::success($this->items)
                    : SupplierResponse::failure('timeout', 'Gamevion did not respond');
            }

            public function createOrder(SupplierOrderRequest $request): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used in this test');
            }
        });
    }

    public function test_job_runs_stage_1_and_2_and_marks_the_run_successful(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR',
        ]);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        $package = Package::query()->create([
            'game_id' => $game->id,
            'name' => '14 Diamond',
            'cost_price' => 1000,
            'reseller_cost_price' => 1150,
            'markup_percent' => 15,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'GV733',
        ]);

        $this->bindFakeAdapter(true, [
            new SupplierCatalogItem('GV733', '14 Diamond', 'Mobile Legends', 12.0, 'active'),
        ]);

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        (new SyncSupplierPricesJob($run))->handle(
            app(\App\Services\Sync\ProductSyncService::class),
            app(\App\Services\Sync\PackagePriceSyncService::class),
            app(SupplierAdapterFactory::class),
        );

        $run->refresh();
        $this->assertSame('success', $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(1, $run->stats['price_changed']);
        $this->assertSame(0, $run->stats['deactivated']);
        $this->assertSame(0, $run->stats['floor_rejected']);
        $this->assertSame(0, $run->stats['price_anomalies']);

        $this->assertSame(1200, $package->refresh()->cost_price); // 12.0 MYR -> 1200 sen
    }

    /** ADR-025 decision #1: surfaced in the run's own stats, not just the counters this session's audit found missing. */
    public function test_job_surfaces_floor_rejections_and_price_anomalies_in_run_stats(): void
    {
        config(['packages.price_swing_threshold_percent' => 50]);
        $supplier = Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR',
        ]);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'cost_price' => 1000, 'reseller_cost_price' => 1150,
            'markup_percent' => 15, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV733',
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '28 Diamond', 'cost_price' => 2000, 'reseller_cost_price' => 2300,
            'markup_percent' => 15, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV734',
        ]);

        $this->bindFakeAdapter(true, [
            new SupplierCatalogItem('GV733', '14 Diamond', 'Mobile Legends', 16.0, 'active'), // +60%, over threshold
            new SupplierCatalogItem('GV734', '28 Diamond', 'Mobile Legends', 0.0, 'active'), // floor violation
        ]);

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        (new SyncSupplierPricesJob($run))->handle(
            app(\App\Services\Sync\ProductSyncService::class),
            app(\App\Services\Sync\PackagePriceSyncService::class),
            app(SupplierAdapterFactory::class),
        );

        $run->refresh();
        $this->assertSame('success', $run->status);
        $this->assertSame(1, $run->stats['price_anomalies']);
        $this->assertSame(1, $run->stats['floor_rejected']);
    }

    public function test_job_marks_the_run_failed_when_the_adapter_call_fails(): void
    {
        Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR',
        ]);
        $this->bindFakeAdapter(false);

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        (new SyncSupplierPricesJob($run))->handle(
            app(\App\Services\Sync\ProductSyncService::class),
            app(\App\Services\Sync\PackagePriceSyncService::class),
            app(SupplierAdapterFactory::class),
        );

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->error_message);
    }

    /**
     * ADR-031 decision 4: loops every active Supplier, aggregating
     * stats across all of them — proves it with two real suppliers,
     * not just the single Gamevion stopgap this job used to hardcode.
     */
    public function test_job_syncs_every_active_supplier_and_aggregates_stats(): void
    {
        $gamevion = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $digiflazz = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz-test', 'api_config' => [], 'currency' => 'IDR']);

        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'cost_price' => 1000, 'reseller_cost_price' => 1150,
            'markup_percent' => 15, 'supplier_id' => $gamevion->id, 'supplier_package_ref' => 'GV733',
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '20 Diamond', 'cost_price' => 1000, 'reseller_cost_price' => 1150,
            'markup_percent' => 15, 'supplier_id' => $digiflazz->id, 'supplier_package_ref' => 'xld20',
        ]);

        $this->bindFakeAdapter(true, [
            new SupplierCatalogItem('GV733', '14 Diamond', 'Mobile Legends', 12.0, 'active'),
        ], 'gamevion');
        $this->bindFakeAdapter(true, [
            new SupplierCatalogItem('xld20', '20 Diamond', 'Mobile Legends', 11.0, 'active'),
        ], 'digiflazz-test');

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        (new SyncSupplierPricesJob($run))->handle(
            app(\App\Services\Sync\ProductSyncService::class),
            app(\App\Services\Sync\PackagePriceSyncService::class),
            app(SupplierAdapterFactory::class),
        );

        $run->refresh();
        $this->assertSame('success', $run->status);
        $this->assertSame(2, $run->stats['price_changed']); // one per supplier
    }

    /** ADR-031: an inactive supplier is never synced. */
    public function test_job_skips_an_inactive_supplier(): void
    {
        Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        Supplier::query()->create(['name' => 'Retired', 'slug' => 'retired-supplier', 'api_config' => [], 'currency' => 'MYR', 'is_active' => false]);

        $this->bindFakeAdapter(true, []);
        // No 'supplier-adapter.retired-supplier' binding at all — if the
        // job tried to sync it, SupplierAdapterFactory::make() would
        // throw UnsupportedSupplierException and this run would fail.

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        (new SyncSupplierPricesJob($run))->handle(
            app(\App\Services\Sync\ProductSyncService::class),
            app(\App\Services\Sync\PackagePriceSyncService::class),
            app(SupplierAdapterFactory::class),
        );

        $run->refresh();
        $this->assertSame('success', $run->status);
    }

    /** ADR-020 decision #5 — its own queue, deliberately separate from the `orders` queue. */
    public function test_runs_on_the_price_sync_queue(): void
    {
        $run = PriceSyncRun::query()->create(['status' => 'queued']);
        $job = new SyncSupplierPricesJob($run);

        $this->assertSame('price-sync', $job->queue);
    }
}
