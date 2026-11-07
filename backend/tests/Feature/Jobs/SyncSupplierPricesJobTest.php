<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SyncSupplierCatalogJob;
use App\Jobs\SyncSupplierPricesJob;
use App\Models\Game;
use App\Models\Package;
use App\Models\PackageReactivationLog;
use App\Models\PriceSyncRun;
use App\Models\Supplier;
use App\Models\SupplierTransfer;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierCatalogItem;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use App\Services\Sync\PackagePriceSyncResult;
use App\Services\Sync\ProductSyncResult;
use App\Services\Sync\ProductSyncService;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
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

        SyncSupplierPricesJob::dispatchSync($run);

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
            new SupplierCatalogItem('GV733', '14 Diamond', 'Mobile Legends', 4.0, 'active'), // -60%, held
            new SupplierCatalogItem('GV734', '28 Diamond', 'Mobile Legends', 0.0, 'active'), // floor violation
        ]);

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        SyncSupplierPricesJob::dispatchSync($run);

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

        SyncSupplierPricesJob::dispatchSync($run);

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->error_message);
        $this->assertSame('gamevion', $run->stats['failed_suppliers'][0]['slug']);
    }

    /**
     * ADR-015 2026-10-17 addendum (PRD §16 item 106): one supplier failing
     * while another succeeds is `partial`, not `success`. The failed
     * supplier and its error are on the run, and only the supplier that
     * synced gets a fresh `last_price_sync_at`.
     */
    public function test_job_marks_the_run_partial_when_one_supplier_fails_and_another_succeeds(): void
    {
        $this->travelTo(now()->subDay());
        $gamevion = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => self::FAKE_CONFIG, 'currency' => 'MYR', 'last_price_sync_at' => now()]);
        $other = Supplier::query()->create(['name' => 'Other', 'slug' => 'other-test', 'api_config' => self::FAKE_CONFIG, 'currency' => 'MYR', 'last_price_sync_at' => now()]);
        $this->travelBack();

        $this->bindFakeAdapter(true, [], 'gamevion');
        $this->bindFakeAdapter(false, [], 'other-test');

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        SyncSupplierPricesJob::dispatchSync($run);

        $run->refresh();
        $this->assertSame('partial', $run->status);
        $this->assertNull($run->error_message);
        $this->assertSame(0, $run->stats['price_changed']);
        $this->assertCount(1, $run->stats['failed_suppliers']);
        $this->assertSame('other-test', $run->stats['failed_suppliers'][0]['slug']);
        $this->assertStringContainsString('Gamevion did not respond', $run->stats['failed_suppliers'][0]['error']);

        $this->assertTrue($gamevion->refresh()->last_price_sync_at->isAfter(now()->subMinute()));
        $this->assertTrue($other->refresh()->last_price_sync_at->isBefore(now()->subHours(23)));
    }

    public function test_a_successful_run_records_an_empty_failed_suppliers_list_and_the_sync_time(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => self::FAKE_CONFIG, 'currency' => 'MYR']);
        $this->bindFakeAdapter(true);

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        SyncSupplierPricesJob::dispatchSync($run);

        $run->refresh();
        $this->assertSame('success', $run->status);
        $this->assertSame([], $run->stats['failed_suppliers']);
        $this->assertNotNull($supplier->refresh()->last_price_sync_at);
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
        // ADR-134: priced at its deposit — funded here at the market rate, so the figures below are unchanged.
        SupplierTransfer::query()->create(['transferred_on' => '2026-10-10', 'supplier_id' => $digiflazz->id, 'source_channel' => 'bank', 'amount_myr_sent' => 22800, 'fee_myr' => 0, 'currency' => 'IDR', 'amount_foreign_received' => '1000000']);

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

        SyncSupplierPricesJob::dispatchSync($run);

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
        // ADR-134: priced at its deposit — funded here at the market rate, so the figures below are unchanged.
        SupplierTransfer::query()->create(['transferred_on' => '2026-10-10', 'supplier_id' => $digiflazz->id, 'source_channel' => 'bank', 'amount_myr_sent' => 22800, 'fee_myr' => 0, 'currency' => 'IDR', 'amount_foreign_received' => '1000000']);

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

        SyncSupplierPricesJob::dispatchSync($run);

        $run->refresh();
        $this->assertCount(1, $run->stats['fx_rates_used']);
        $used = $run->stats['fx_rates_used'][0];
        $this->assertEquals(['IDR', 'MYR', 'deposit pool', 0.000228, 0.0], [$used['from'], $used['to'], $used['source'], $used['market_rate'], $used['spread_pct']]);
        $this->assertEqualsWithDelta(0.000228, $used['rate'], 1e-12);
    }

    public function test_job_records_an_empty_fx_rates_used_array_when_every_supplier_is_myr(): void
    {
        Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => self::FAKE_CONFIG, 'currency' => 'MYR']);
        $this->bindFakeAdapter(true, []);

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        SyncSupplierPricesJob::dispatchSync($run);

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

        SyncSupplierPricesJob::dispatchSync($run);

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

        SyncSupplierPricesJob::dispatchSync($run);

        $run->refresh();
        $this->assertSame('success', $run->status);
        $this->assertNotNull($run->finished_at);
    }

    /** Pre-ADR-046 stopgap removed: the job must not manufacture a Supplier row. */
    public function test_job_does_not_create_a_gamevion_supplier_row(): void
    {
        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        SyncSupplierPricesJob::dispatchSync($run);

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

    /**
     * ADR-100 — end-to-end wiring: with the toggle on, a package
     * already confirmed active + stable enough gets auto-approved as
     * part of this same run, and the run itself still succeeds.
     */
    public function test_job_auto_approves_a_stability_confirmed_pending_reactivation_when_enabled(): void
    {
        config([
            'packages.pending_reactivation_auto_approve' => true,
            'packages.reactivation_stability_syncs' => 1,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => self::FAKE_CONFIG, 'currency' => 'MYR',
        ]);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'is_active' => false, 'deactivated_reason' => 'supplier_sync', 'deactivated_at' => now()->subHour(),
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV733',
        ]);

        $this->bindFakeAdapter(true, [
            new SupplierCatalogItem('GV733', '14 Diamond', 'Mobile Legends', 12.0, 'active'),
        ]);

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        SyncSupplierPricesJob::dispatchSync($run);

        $this->assertSame('success', $run->refresh()->status);
        $this->assertTrue($package->fresh()->is_active);
        $this->assertDatabaseHas('package_reactivation_logs', [
            'package_id' => $package->id, 'trigger' => 'stability_confirmed', 'price_sync_run_id' => $run->id,
        ]);
    }

    /**
     * ADR-100 — the same scenario as above, toggle off (the real
     * default): the run still succeeds, but nothing gets auto-approved.
     */
    public function test_job_leaves_pending_reactivation_untouched_when_auto_approve_is_disabled(): void
    {
        config(['packages.pending_reactivation_auto_approve' => false]);

        $supplier = Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => self::FAKE_CONFIG, 'currency' => 'MYR',
        ]);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'is_active' => false, 'deactivated_reason' => 'supplier_sync', 'deactivated_at' => now()->subHour(),
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV733',
        ]);

        $this->bindFakeAdapter(true, [
            new SupplierCatalogItem('GV733', '14 Diamond', 'Mobile Legends', 12.0, 'active'),
        ]);

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        SyncSupplierPricesJob::dispatchSync($run);

        $this->assertSame('success', $run->refresh()->status);
        $this->assertFalse($package->fresh()->is_active);
        $this->assertSame(0, PackageReactivationLog::query()->count());
    }

    private function runJob(): PriceSyncRun
    {
        $run = PriceSyncRun::query()->create(['status' => 'queued']);
        SyncSupplierPricesJob::dispatchSync($run);

        return $run->refresh();
    }

    /**
     * ADR-134 decision 4: a foreign supplier with no deposit stops selling,
     * and once a deposit is recorded exactly those packages come back, at
     * the landed cost — not one the admin switched off, nor one the
     * supplier no longer lists.
     */
    public function test_an_unfunded_suppliers_packages_stop_selling_until_a_deposit_is_recorded(): void
    {
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['MYR' => 0.000228]], 200)]);
        $digiflazz = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz-test', 'api_config' => self::FAKE_CONFIG, 'currency' => 'IDR']);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        $package = fn (string $ref, bool $active = true, ?string $reason = null) => Package::query()->create([
            'game_id' => $game->id, 'name' => $ref, 'cost_price' => 73, 'standard_selling_price' => 84, 'markup_percent' => 15,
            'supplier_id' => $digiflazz->id, 'supplier_package_ref' => $ref, 'is_active' => $active, 'deactivated_reason' => $reason,
        ]);
        $selling = $package('xld10');
        $gone = $package('xld20');
        $adminOff = $package('xld30', false, 'admin');

        $this->bindFakeAdapter(true, [
            new SupplierCatalogItem('xld10', '10', 'Mobile Legends', 3200.0, 'active'),
            new SupplierCatalogItem('xld20', '20', 'Mobile Legends', 6400.0, 'active'),
            new SupplierCatalogItem('xld30', '30', 'Mobile Legends', 9600.0, 'active'),
        ], 'digiflazz-test');

        $this->assertSame('failed', $this->runJob()->status);
        $this->assertSame([false, 'unfunded'], [$selling->fresh()->is_active, $selling->fresh()->deactivated_reason]);
        $this->assertSame('unfunded', $gone->fresh()->deactivated_reason);
        $this->assertSame('admin', $adminOff->fresh()->deactivated_reason);

        SupplierTransfer::query()->create(['transferred_on' => '2026-10-10', 'supplier_id' => $digiflazz->id, 'source_channel' => 'bank', 'amount_myr_sent' => 23900, 'fee_myr' => 0, 'currency' => 'IDR', 'amount_foreign_received' => '1000000']);
        $this->bindFakeAdapter(true, [
            new SupplierCatalogItem('xld10', '10', 'Mobile Legends', 3200.0, 'active'),
            new SupplierCatalogItem('xld30', '30', 'Mobile Legends', 9600.0, 'active'),
        ], 'digiflazz-test');

        $this->assertSame('success', $this->runJob()->status);
        $this->assertSame([true, null, 77], [$selling->fresh()->is_active, $selling->fresh()->deactivated_reason, $selling->fresh()->cost_price]);
        $this->assertFalse($gone->fresh()->is_active);
        $this->assertSame([false, 'admin'], [$adminOff->fresh()->is_active, $adminOff->fresh()->deactivated_reason]);
    }

    /** ADR-137 2026-11-05 decision 3: every supplier gets its own job and its own time limit. */
    public function test_each_syncable_supplier_syncs_in_its_own_job_on_the_price_sync_queue(): void
    {
        Bus::fake();
        Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => self::FAKE_CONFIG, 'currency' => 'MYR']);
        Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => self::FAKE_CONFIG, 'currency' => 'IDR']);
        Supplier::query()->create(['name' => 'Off', 'slug' => 'off', 'api_config' => self::FAKE_CONFIG, 'currency' => 'MYR', 'is_active' => false]);
        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        (new SyncSupplierPricesJob($run))->handle();

        Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === 2
            && $batch->jobs->every(fn ($job) => $job instanceof SyncSupplierCatalogJob && $job->queue === 'price-sync')
            && $batch->allowsFailures());
        $this->assertSame('running', $run->refresh()->status);
    }

    /**
     * A supplier job killed by its time limit lands in failed(). The batch
     * may finish the run before or after that hook, so the status counts
     * the suppliers that synced, never the failures recorded so far.
     */
    public function test_a_supplier_job_that_times_out_leaves_the_run_partial_either_way_round(): void
    {
        $gamevion = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => self::FAKE_CONFIG, 'currency' => 'MYR']);

        foreach (['finish first', 'failed() first'] as $order) {
            $run = PriceSyncRun::query()->create(['status' => 'running', 'stats' => PriceSyncRun::emptyStats()]);
            $run->recordSupplierSynced('digiflazz', new ProductSyncResult(total: 10, created: 0, updated: 10, durationMs: 1, syncedAt: now()), new PackagePriceSyncResult(priceChanged: 1, deactivated: 0, affectedGameIds: []));

            if ($order === 'failed() first') {
                (new SyncSupplierCatalogJob($run, $gamevion))->failed(new RuntimeException('Job has timed out.'));
                $run->finishBatch(2);
            } else {
                $run->finishBatch(2);
                (new SyncSupplierCatalogJob($run, $gamevion))->failed(new RuntimeException('Job has timed out.'));
            }

            $run->refresh();
            $this->assertSame('partial', $run->status, $order);
            $this->assertSame([['slug' => 'gamevion', 'error' => 'Job has timed out.']], $run->stats['failed_suppliers'], $order);
            $this->assertSame(10, $run->stats['catalog_total'], $order);
        }
    }

    public function test_the_run_fails_when_no_supplier_synced(): void
    {
        $run = PriceSyncRun::query()->create(['status' => 'running', 'stats' => PriceSyncRun::emptyStats()]);
        $run->recordSupplierFailed('gamevion', 'Gamevion did not respond');

        $run->finishBatch(1);

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('Gamevion did not respond', $run->error_message);
        $this->assertNotNull($run->finished_at);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** One 30-minute sync of the given catalog, the way the batch runs it. */
    private function syncAt(Supplier $supplier, array $items, int $minutes): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-07 00:00:00')->addMinutes($minutes));
        $this->bindFakeAdapter(true, $items);
        app()->call([new SyncSupplierCatalogJob(PriceSyncRun::query()->create(['status' => 'running']), $supplier), 'handle']);
    }

    /** @return array{0: Supplier, 1: Package} a package on sale whose item has been active for 6 syncs */
    private function establishedPackage(array $items): array
    {
        config(['packages.pending_reactivation_auto_approve' => true, 'packages.reactivation_stability_syncs' => 6]);
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => self::FAKE_CONFIG, 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'PUBG', 'slug' => 'pubg']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '60 UC', 'cost_price' => 1200, 'standard_selling_price' => 1320, 'markup_percent' => 10,
            'is_active' => true, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'pubgm_global:60',
        ]);

        foreach (range(0, 5) as $sync) {
            $this->syncAt($supplier, $items, $sync * 30);
        }

        return [$supplier, $package];
    }

    /**
     * Item 173: a game skipped on a 503 leaves its row untouched, so the
     * streak survives and the package is back on the next good sync, not
     * after 6 more (ADR-137 2026-11-05 addendum, decision 2).
     */
    public function test_a_package_skipped_by_one_failed_game_is_back_on_the_next_good_sync(): void
    {
        $items = [new SupplierCatalogItem('pubgm_global:60', '60 UC', 'PUBG', 12.0, 'active'), new SupplierCatalogItem('other:1', 'Other', 'Other', 5.0, 'active')];
        [$supplier, $package] = $this->establishedPackage($items);
        $this->assertTrue($package->fresh()->is_active);

        $this->syncAt($supplier, [$items[1]], 180);
        $this->assertFalse($package->fresh()->is_active);
        $this->assertSame('supplier_sync', $package->fresh()->deactivated_reason);

        $this->syncAt($supplier, $items, 210);
        $this->assertTrue($package->fresh()->is_active);
    }

    /** Item 173: the supplier saying inactive is real evidence, so the package waits out the full streak. */
    public function test_a_package_the_supplier_marks_inactive_waits_six_syncs(): void
    {
        $active = new SupplierCatalogItem('pubgm_global:60', '60 UC', 'PUBG', 12.0, 'active');
        [$supplier, $package] = $this->establishedPackage([$active]);

        $this->syncAt($supplier, [new SupplierCatalogItem('pubgm_global:60', '60 UC', 'PUBG', 12.0, 'inactive')], 180);
        $this->assertFalse($package->fresh()->is_active);

        foreach (range(1, 5) as $sync) {
            $this->syncAt($supplier, [$active], 180 + $sync * 30);
            $this->assertFalse($package->fresh()->is_active, "sync {$sync}");
        }

        $this->syncAt($supplier, [$active], 360);
        $this->assertTrue($package->fresh()->is_active);
    }
}
