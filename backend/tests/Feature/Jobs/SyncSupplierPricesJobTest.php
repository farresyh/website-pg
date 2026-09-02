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
use App\Services\Sync\PackagePriceSyncService;
use App\Services\Sync\ProductSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SyncSupplierPricesJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Any non-empty api_config — the job now skips a supplier whose
     * api_config is blank (it can't be called), and every test here
     * binds a fake adapter anyway, so the contents are irrelevant, only
     * that it is not empty.
     */
    private const FAKE_CONFIG = ['base_url' => 'https://fake.test', 'bearer_token' => 't', 'api_key' => 'k', 'sandbox' => true];

    private function bindFakeAdapter(bool $success, array $items = [], string $slug = 'gamevion'): void
    {
        $this->app->bind("supplier-adapter.{$slug}", fn () => new class($success, $items) implements SupplierAdapter
        {
            public function __construct(
                private readonly bool $success,
                private readonly array $items,
            ) {}

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
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => self::FAKE_CONFIG, 'currency' => 'MYR',
        ]);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        $package = Package::query()->create([
            'game_id' => $game->id,
            'name' => '14 Diamond',
            'cost_price' => 1000,
            'standard_selling_price' => 1150,
            'markup_percent' => 15,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'GV733',
        ]);

        $this->bindFakeAdapter(true, [
            new SupplierCatalogItem('GV733', '14 Diamond', 'Mobile Legends', 12.0, 'active'),
        ]);

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        (new SyncSupplierPricesJob($run))->handle(
            app(ProductSyncService::class),
            app(PackagePriceSyncService::class),
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
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => self::FAKE_CONFIG, 'currency' => 'MYR',
        ]);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'markup_percent' => 15, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV733',
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '28 Diamond', 'cost_price' => 2000, 'standard_selling_price' => 2300,
            'markup_percent' => 15, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV734',
        ]);

        $this->bindFakeAdapter(true, [
            new SupplierCatalogItem('GV733', '14 Diamond', 'Mobile Legends', 16.0, 'active'), // +60%, over threshold
            new SupplierCatalogItem('GV734', '28 Diamond', 'Mobile Legends', 0.0, 'active'), // floor violation
        ]);

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        (new SyncSupplierPricesJob($run))->handle(
            app(ProductSyncService::class),
            app(PackagePriceSyncService::class),
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
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => self::FAKE_CONFIG, 'currency' => 'MYR',
        ]);
        $this->bindFakeAdapter(false);

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        (new SyncSupplierPricesJob($run))->handle(
            app(ProductSyncService::class),
            app(PackagePriceSyncService::class),
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
        $gamevion = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => self::FAKE_CONFIG, 'currency' => 'MYR']);
        $digiflazz = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz-test', 'api_config' => self::FAKE_CONFIG, 'currency' => 'IDR']);

        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'markup_percent' => 15, 'supplier_id' => $gamevion->id, 'supplier_package_ref' => 'GV733',
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '20 Diamond', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'markup_percent' => 15, 'supplier_id' => $digiflazz->id, 'supplier_package_ref' => 'xld20',
        ]);

        $this->bindFakeAdapter(true, [
            new SupplierCatalogItem('GV733', '14 Diamond', 'Mobile Legends', 12.0, 'active'),
        ], 'gamevion');
        $this->bindFakeAdapter(true, [
            // A realistic IDR magnitude (~44,000) so the converted sen
            // value lands close to the package's existing cost_price
            // (1000 sen) — ADR-033: 44000 * 0.000228 rate * 100 sen =
            // ~1004 sen, well inside ADR-025's swing threshold, so this
            // counts as an ordinary price_changed like Gamevion's own
            // item, not a flagged anomaly.
            new SupplierCatalogItem('xld20', '20 Diamond', 'Mobile Legends', 44000.0, 'active'),
        ], 'digiflazz-test');
        // ADR-033: digiflazz-test is IDR, so ProductSyncService needs a real (faked) FX rate to convert with.
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['MYR' => 0.000228]], 200)]);

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        (new SyncSupplierPricesJob($run))->handle(
            app(ProductSyncService::class),
            app(PackagePriceSyncService::class),
            app(SupplierAdapterFactory::class),
        );

        $run->refresh();
        $this->assertSame('success', $run->status);
        $this->assertSame(2, $run->stats['price_changed']); // one per supplier
    }

    /**
     * ADR-033 addendum decision 3: fx_rates_used is an array from day
     * one (even with only one real non-MYR pair today) — Gamevion's
     * own MYR sync contributes nothing to it.
     */
    public function test_job_records_the_fx_rate_used_for_a_non_myr_supplier(): void
    {
        $gamevion = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => self::FAKE_CONFIG, 'currency' => 'MYR']);
        $digiflazz = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz-test', 'api_config' => self::FAKE_CONFIG, 'currency' => 'IDR']);

        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'markup_percent' => 15, 'supplier_id' => $gamevion->id, 'supplier_package_ref' => 'GV733',
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '20 Diamond', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'markup_percent' => 15, 'supplier_id' => $digiflazz->id, 'supplier_package_ref' => 'xld20',
        ]);

        $this->bindFakeAdapter(true, [
            new SupplierCatalogItem('GV733', '14 Diamond', 'Mobile Legends', 12.0, 'active'),
        ], 'gamevion');
        $this->bindFakeAdapter(true, [
            new SupplierCatalogItem('xld20', '20 Diamond', 'Mobile Legends', 44000.0, 'active'),
        ], 'digiflazz-test');
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['MYR' => 0.000228]], 200)]);

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        (new SyncSupplierPricesJob($run))->handle(
            app(ProductSyncService::class),
            app(PackagePriceSyncService::class),
            app(SupplierAdapterFactory::class),
        );

        $run->refresh();
        $this->assertSame(
            [['from' => 'IDR', 'to' => 'MYR', 'rate' => 0.000228, 'source' => 'open.er-api.com']],
            $run->stats['fx_rates_used'],
        );
    }

    public function test_job_records_an_empty_fx_rates_used_array_when_every_supplier_is_myr(): void
    {
        Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => self::FAKE_CONFIG, 'currency' => 'MYR']);
        $this->bindFakeAdapter(true, []);

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        (new SyncSupplierPricesJob($run))->handle(
            app(ProductSyncService::class),
            app(PackagePriceSyncService::class),
            app(SupplierAdapterFactory::class),
        );

        $run->refresh();
        $this->assertSame([], $run->stats['fx_rates_used']);
    }

    /** ADR-031: an inactive supplier is never synced. */
    public function test_job_skips_an_inactive_supplier(): void
    {
        Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => self::FAKE_CONFIG, 'currency' => 'MYR']);
        Supplier::query()->create(['name' => 'Retired', 'slug' => 'retired-supplier', 'api_config' => self::FAKE_CONFIG, 'currency' => 'MYR', 'is_active' => false]);

        $this->bindFakeAdapter(true, []);
        // No 'supplier-adapter.retired-supplier' binding at all — if the
        // job tried to sync it, SupplierAdapterFactory::make() would
        // throw UnsupportedSupplierException and this run would fail.

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        (new SyncSupplierPricesJob($run))->handle(
            app(ProductSyncService::class),
            app(PackagePriceSyncService::class),
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

    /**
     * A supplier that is active but not yet configured (empty api_config
     * — a row created via /admin but not filled, or a leftover) is
     * skipped, not attempted-then-failed: a tick with nothing syncable
     * is a clean no-op 'success', not a 'failed' run.
     */
    public function test_job_is_a_clean_no_op_success_when_the_only_active_supplier_has_no_api_config(): void
    {
        Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        // deliberately NO adapter binding — if the job tried to build one
        // for an empty-config supplier it would throw and fail the run.

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        (new SyncSupplierPricesJob($run))->handle(
            app(ProductSyncService::class),
            app(PackagePriceSyncService::class),
            app(SupplierAdapterFactory::class),
        );

        $run->refresh();
        $this->assertSame('success', $run->status);
        $this->assertNotNull($run->finished_at);
    }

    /** Pre-ADR-046 stopgap removed: the job must not manufacture a Supplier row. */
    public function test_job_does_not_create_a_gamevion_supplier_row(): void
    {
        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        (new SyncSupplierPricesJob($run))->handle(
            app(ProductSyncService::class),
            app(PackagePriceSyncService::class),
            app(SupplierAdapterFactory::class),
        );

        $this->assertDatabaseMissing('suppliers', ['slug' => 'gamevion']);
        $this->assertSame('success', $run->refresh()->status);
    }

    /**
     * $tries = 1, so a throw outside handle()'s per-supplier try/catch
     * (worker timeout, OOM, an orchestration bug) lands in failed().
     * Without it the run is stranded at 'running' forever — the real
     * cause of the ~50 stuck runs found in production 2026-09-02.
     */
    public function test_failed_handler_marks_a_stranded_run_as_failed(): void
    {
        $run = PriceSyncRun::query()->create(['status' => 'running', 'started_at' => now()]);

        (new SyncSupplierPricesJob($run))->failed(new RuntimeException('worker timed out'));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('worker timed out', $run->error_message);
        $this->assertNotNull($run->finished_at);
    }
}
