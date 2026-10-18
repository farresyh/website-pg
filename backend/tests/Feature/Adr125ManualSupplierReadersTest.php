<?php

namespace Tests\Feature;

use App\Jobs\CheckSupplierDeliveryJob;
use App\Jobs\SyncSupplierPricesJob;
use App\Models\AdminUser;
use App\Models\PriceSyncRun;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Services\Accounting\SupplierLedgerEntryType;
use App\Services\Backup\BackupFailureAlerter;
use App\Services\Dashboard\DashboardService;
use App\Services\Fulfillment\SupplierDeliveryCheckService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Supplier\Manual\ManualSupplierAdapter;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\UnsupportedSupplierException;
use App\Services\Sync\PackagePriceSyncService;
use App\Services\Sync\PendingReactivationAutoApprover;
use App\Services\Sync\ProductSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Support\AdminOrderControllerFixtures;
use Tests\TestCase;

/**
 * ADR-125 decisions 1-3, 18, 21 and the 2026-10-18 addendum (decisions
 * 26-27): once a manual supplier exists, nothing that only makes sense for
 * an API supplier may touch it — no sync, no balance poll, no status check,
 * no stale-sync email — and its balance is its funding ledger.
 */
class Adr125ManualSupplierReadersTest extends TestCase
{
    use AdminOrderControllerFixtures;
    use RefreshDatabase;

    private function manualSupplier(array $overrides = []): Supplier
    {
        return Supplier::query()->create(array_merge([
            'name' => 'Roblox Supplier A', 'slug' => 'roblox-a-'.uniqid(), 'driver' => Supplier::DRIVER_MANUAL,
            'currency' => 'MYR', 'is_active' => true,
            // Its own settings make api_config non-empty, which used to read as "syncable".
            'api_config' => ['reply_sla_minutes' => '30'],
        ], $overrides));
    }

    private function superAdmin(): void
    {
        $this->actingAsSteppedUp(AdminUser::factory()->superAdmin()->create());
    }

    public function test_the_factory_resolves_by_driver_and_the_manual_adapter_refuses_every_api_call(): void
    {
        $supplier = $this->manualSupplier();
        $adapter = app(SupplierAdapterFactory::class)->for($supplier);

        $this->assertInstanceOf(ManualSupplierAdapter::class, $adapter);
        $this->expectException(UnsupportedSupplierException::class);
        $adapter->listProducts();
    }

    /** The e2e regression (PR #507 CI): seeders run WithoutModelEvents, so the driver must come from the read, not a hook. */
    public function test_a_supplier_stored_without_a_driver_reads_its_slug_as_driver(): void
    {
        $seeded = Supplier::withoutEvents(fn () => Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'currency' => 'MYR', 'api_config' => []]));

        $this->assertSame('', DB::table('suppliers')->where('id', $seeded->id)->value('driver'));
        $this->assertSame('gamevion', $seeded->fresh()->driver);
        $this->assertSame(1, Supplier::query()->apiBacked()->count());
    }

    public function test_only_digiflazz_replays_a_resubmit_and_it_is_read_from_the_driver(): void
    {
        $digiflazz = Supplier::query()->create(['name' => 'DigiFlazz', 'slug' => 'digiflazz', 'currency' => 'IDR', 'api_config' => []]);

        $this->assertSame('digiflazz', $digiflazz->driver);
        $this->assertTrue(SupplierAdapterFactory::resubmitReplaysOutcome($digiflazz));
        $this->assertFalse(SupplierAdapterFactory::resubmitReplaysOutcome($this->manualSupplier()));
        $this->assertFalse(SupplierAdapterFactory::resubmitReplaysOutcome(null));
    }

    public function test_an_api_supplier_takes_its_driver_as_slug_and_a_manual_one_names_itself(): void
    {
        $this->superAdmin();

        $this->postJson('/api/middleware/suppliers', ['name' => 'Digiflazz', 'driver' => 'digiflazz', 'slug' => 'something-else', 'currency' => 'IDR', 'funding_mode' => 'pay_after'])
            ->assertCreated()->assertJsonPath('slug', 'digiflazz')->assertJsonPath('funding_mode', 'prepaid');

        foreach (['roblox-a', 'roblox-b'] as $slug) {
            $this->postJson('/api/middleware/suppliers', ['name' => $slug, 'driver' => 'manual', 'slug' => $slug, 'currency' => 'MYR', 'funding_mode' => 'pay_after'])
                ->assertCreated()->assertJsonPath('driver', 'manual');
        }

        $this->postJson('/api/middleware/suppliers', ['name' => 'X', 'driver' => 'manual', 'slug' => 'x-idr', 'currency' => 'IDR', 'funding_mode' => 'prepaid'])
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->postJson('/api/middleware/suppliers', ['name' => 'X', 'driver' => 'made-up', 'currency' => 'MYR'])
            ->assertUnprocessable()->assertJsonValidationErrors('driver');
        $this->getJson('/api/middleware/suppliers/available-drivers')->assertOk()->assertJsonFragment(['manual']);
    }

    public function test_an_api_supplier_stays_prepaid_and_a_manual_one_stays_in_ringgit(): void
    {
        $this->superAdmin();
        $api = Supplier::query()->create(['name' => 'DigiFlazz', 'slug' => 'digiflazz', 'currency' => 'IDR', 'api_config' => []]);
        $manual = $this->manualSupplier();

        $this->putJson("/api/middleware/suppliers/{$api->id}", ['funding_mode' => 'pay_after'])->assertUnprocessable()->assertJsonValidationErrors('funding_mode');
        $this->putJson("/api/middleware/suppliers/{$manual->id}", ['currency' => 'IDR'])->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->putJson("/api/middleware/suppliers/{$manual->id}", ['funding_mode' => 'pay_after'])->assertOk()->assertJsonPath('funding_mode', 'pay_after');
    }

    public function test_price_sync_its_schedule_gate_and_the_staleness_alert_leave_a_manual_supplier_alone(): void
    {
        $this->manualSupplier();
        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        (new SyncSupplierPricesJob($run))->handle(
            app(ProductSyncService::class),
            app(PackagePriceSyncService::class),
            app(SupplierAdapterFactory::class),
            app(PendingReactivationAutoApprover::class),
        );

        $run->refresh();
        $this->assertSame('success', $run->status);
        $this->assertSame([], $run->stats['failed_suppliers']);
        $this->assertFalse(Supplier::query()->get()->contains(fn (Supplier $s) => $s->isSyncable()));

        $alerter = Mockery::spy(BackupFailureAlerter::class);
        $this->app->instance(BackupFailureAlerter::class, $alerter);
        $this->travel(3)->hours();
        $this->artisan('app:check-price-sync-staleness')->assertSuccessful();
        $alerter->shouldNotHaveReceived('alert');
    }

    public function test_product_sync_and_the_balance_refresh_skip_a_manual_supplier(): void
    {
        $this->manualSupplier();

        $this->artisan('app:refresh-supplier-balances')->assertSuccessful();
        // Product sync seeds a Gamevion row and fails only it (unconfigured), never the manual one.
        $this->artisan('app:sync-supplier-products')->doesntExpectOutputToContain('roblox-a');
    }

    public function test_balance_refresh_status_check_and_the_developer_tester_refuse_a_manual_supplier(): void
    {
        $this->superAdmin();
        $supplier = $this->manualSupplier();

        $this->postJson("/api/middleware/suppliers/{$supplier->id}/refresh-balance")->assertUnprocessable();
        $this->postJson('/api/middleware/developer-tools/test', ['supplier_id' => $supplier->id, 'method' => 'checkBalance', 'dry_run' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('supplier');

        $order = $this->order(['supplier_id' => $supplier->id, 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Pending->value]);
        $this->assertFalse(SupplierDeliveryCheckService::canAsk($order));
        $this->postJson("/api/orders/{$order->id}/check-supplier")->assertUnprocessable();
    }

    public function test_a_manual_pending_order_is_never_polled_or_aged_out_and_is_stale_after_its_sla(): void
    {
        Bus::fake();
        $supplier = $this->manualSupplier(['api_config' => ['reply_sla_minutes' => '45']]);
        $order = $this->order(['supplier_id' => $supplier->id, 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Pending->value]);

        $this->travel(40)->minutes();
        $this->assertFalse($order->fresh()->isStalePending());

        $this->travel(10)->minutes();
        $this->assertTrue($order->fresh()->isStalePending());

        // Far past every API window (pending_max_hours, max_reconcile_age_days).
        $this->travel(100)->days();
        $this->artisan('app:reconcile-pending-deliveries')->assertSuccessful();
        Bus::assertNotDispatched(CheckSupplierDeliveryJob::class);
        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
    }

    public function test_the_dashboard_counts_stale_pending_by_each_suppliers_own_window(): void
    {
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());
        $manual = $this->manualSupplier();
        $api = Supplier::query()->create(['name' => 'DigiFlazz', 'slug' => 'digiflazz', 'currency' => 'IDR', 'api_config' => []]);
        $this->order(['supplier_id' => $manual->id, 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Pending->value]);
        $this->order(['supplier_id' => $api->id, 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Pending->value]);

        // 15 minutes: past the API default (10), inside the manual SLA (30).
        $this->travel(15)->minutes();

        $this->assertSame(1, app(DashboardService::class)->health()['stuck_orders']['value']);
    }

    public function test_a_manual_suppliers_balance_is_its_ledger_and_stays_out_of_wallet_coverage(): void
    {
        $prepaid = $this->manualSupplier();
        $payAfter = $this->manualSupplier(['funding_mode' => Supplier::FUNDING_PAY_AFTER]);
        SupplierLedgerEntry::query()->create(['supplier_id' => $prepaid->id, 'type' => SupplierLedgerEntryType::Topup->value, 'amount' => '200.0000', 'currency' => 'MYR', 'reference_type' => 'supplier_transfer', 'reference_id' => 1]);
        SupplierLedgerEntry::query()->create(['supplier_id' => $payAfter->id, 'type' => SupplierLedgerEntryType::OrderDrawdown->value, 'amount' => '-65.0000', 'currency' => 'MYR', 'reference_type' => 'order', 'reference_id' => 999]);

        $this->assertSame(200.0, $prepaid->displayBalance());
        $this->assertSame(-65.0, $payAfter->displayBalance());
        $this->assertTrue($payAfter->isPayAfter());
        $this->assertNull($payAfter->fundingDrift());

        $health = app(DashboardService::class)->health();
        $this->assertSame(-65.0, collect($health['suppliers'])->firstWhere('id', $payAfter->id)['balance']);
        $this->assertEquals(0, $health['total_supplier_balance_myr_equivalent']);
    }

    public function test_the_dashboard_flags_a_delivered_manual_order_with_no_drawdown(): void
    {
        $supplier = $this->manualSupplier();
        $missing = $this->order(['supplier_id' => $supplier->id, 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value]);
        $recorded = $this->order(['supplier_id' => $supplier->id, 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value]);
        SupplierLedgerEntry::query()->create(['supplier_id' => $supplier->id, 'type' => SupplierLedgerEntryType::OrderDrawdown->value, 'amount' => '-9.0000', 'currency' => 'MYR', 'reference_type' => 'order', 'reference_id' => $recorded->id]);

        $items = collect(app(DashboardService::class)->health()['attention']['items'] ?? [])->keyBy('key');

        $this->assertSame(1, $items['manual_delivery_without_drawdown']['count'] ?? null, "order {$missing->id} should be the one counted");
    }
}
