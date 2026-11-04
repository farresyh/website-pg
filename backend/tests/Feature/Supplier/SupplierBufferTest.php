<?php

namespace Tests\Feature\Supplier;

use App\Jobs\CheckSupplierBufferJob;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Supplier;
use App\Services\Accounting\SupplierFundingService;
use App\Services\Backup\BackupFailureAlerter;
use App\Services\Supplier\SupplierBufferAlert;
use App\Services\Supplier\SupplierBufferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ADR-115 2026-11-05 addendum: the buffer is the busiest window of the last
 * 60 Malaysia days, the window runs through Monday from Thursday, and the
 * required balance is the higher of the admin's floor and that forecast.
 */
class SupplierBufferTest extends TestCase
{
    use RefreshDatabase;

    private function supplier(array $config = [], array $attributes = []): Supplier
    {
        return Supplier::query()->create(array_merge([
            'name' => 'Digiflazz', 'slug' => 'digiflazz', 'driver' => 'digiflazz', 'currency' => 'IDR',
            'is_active' => true, 'balance' => 20_000_000,
            'api_config' => array_merge(['base_url' => 'https://fake.test'], $config),
        ], $attributes));
    }

    /** A drawdown at a Malaysia wall-clock time (stored in UTC, as the app does). */
    private function spend(Supplier $supplier, float $idr, string $malaysiaTime): void
    {
        DB::table('supplier_ledger_entries')->insert([
            'supplier_id' => $supplier->id, 'type' => 'ORDER_DRAWDOWN', 'amount' => -$idr, 'currency' => 'IDR',
            'reference_type' => 'order', 'reference_id' => random_int(1, PHP_INT_MAX),
            'created_at' => Carbon::parse($malaysiaTime, 'Asia/Kuala_Lumpur')->utc(),
        ]);
    }

    private function topup(Supplier $supplier, float $idr): void
    {
        DB::table('supplier_ledger_entries')->insert([
            'supplier_id' => $supplier->id, 'type' => 'TOPUP', 'amount' => $idr, 'currency' => 'IDR',
            'created_at' => now()->subDays(90),
        ]);
    }

    private function at(string $malaysiaTime): void
    {
        $this->travelTo(Carbon::parse($malaysiaTime, 'Asia/Kuala_Lumpur'));
    }

    private function assess(Supplier $supplier)
    {
        return app(SupplierBufferService::class)->assess($supplier->fresh());
    }

    private function alerter(): Mockery\MockInterface
    {
        return app(BackupFailureAlerter::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(BackupFailureAlerter::class, Mockery::spy(BackupFailureAlerter::class));
    }

    public function test_the_forecast_is_the_busiest_window_with_quiet_days_counted_as_zero(): void
    {
        $this->at('2026-11-04 12:00'); // Wednesday: a 2-day window
        $s = $this->supplier();
        $this->spend($s, 5_000_000, '2026-10-31 10:00');
        $this->spend($s, 2_000_000, '2026-11-01 10:00');
        $this->spend($s, 6_000_000, '2026-10-20 10:00'); // alone: its neighbours are empty
        $this->spend($s, 99_000_000, '2026-09-04 10:00'); // 61 days back: outside the window

        $buffer = $this->assess($s);

        $this->assertSame(2, $buffer->windowDays);
        $this->assertEqualsWithDelta(7_000_000, $buffer->forecast, 0.01);
        $this->assertSame('2026-10-31', $buffer->busiestFrom?->toDateString());
        $this->assertSame('2026-11-01', $buffer->busiestTo?->toDateString());
    }

    /** @return array<string, array{string, int, string}> */
    public static function weekdays(): array
    {
        return [
            'Monday' => ['2026-11-09 12:00', 2, '2026-11-10'],
            'Wednesday' => ['2026-11-11 12:00', 2, '2026-11-12'],
            'Thursday' => ['2026-11-12 12:00', 5, '2026-11-16'],
            'Friday' => ['2026-11-13 12:00', 4, '2026-11-16'],
            'Saturday' => ['2026-11-14 12:00', 3, '2026-11-16'],
            'Sunday' => ['2026-11-15 12:00', 2, '2026-11-16'],
        ];
    }

    #[DataProvider('weekdays')]
    public function test_from_thursday_the_window_runs_through_monday(string $now, int $days, string $until): void
    {
        $this->at($now);
        $s = $this->supplier(['low_balance_threshold' => '1']);

        $buffer = $this->assess($s);

        $this->assertSame($days, $buffer->windowDays);
        $this->assertSame($until, $buffer->coversUntil->toDateString());
    }

    public function test_the_window_is_never_shorter_than_the_lead_time(): void
    {
        $this->at('2026-11-15 12:00'); // Sunday: through Monday is 2 days
        $s = $this->supplier(['low_balance_threshold' => '1', 'topup_lead_days' => '3']);

        $this->assertSame(3, $this->assess($s)->windowDays);
    }

    public function test_the_weekday_and_the_day_buckets_follow_malaysia_midnight_not_utc(): void
    {
        $s = $this->supplier(['low_balance_threshold' => '1']);
        $this->spend($s, 1_000_000, '2026-11-12 23:30'); // Thursday night MYT = Thursday 15:30 UTC
        $this->spend($s, 4_000_000, '2026-11-13 00:30'); // Friday 00:30 MYT = Thursday 16:30 UTC

        $this->at('2026-11-11 23:59');
        $this->assertSame(2, $this->assess($s)->windowDays, 'still Wednesday in Malaysia');

        $this->at('2026-11-12 00:00');
        $this->assertSame(5, $this->assess($s)->windowDays, 'Thursday in Malaysia although UTC still says Wednesday');

        $this->at('2026-11-17 12:00'); // Tuesday, 2-day window
        $buffer = $this->assess($s);
        $this->assertSame('2026-11-12', $buffer->busiestFrom?->toDateString());
        $this->assertEqualsWithDelta(5_000_000, $buffer->forecast, 0.01, 'the two drawdowns fall on Thursday and Friday');
    }

    public function test_required_is_the_higher_of_the_floor_and_the_forecast_with_its_margin(): void
    {
        $this->at('2026-11-04 12:00');
        $s = $this->supplier(['low_balance_threshold' => '10000000', 'buffer_margin_pct' => '20']);
        $this->spend($s, 8_000_000, '2026-11-03 10:00');

        $this->assertEqualsWithDelta(10_000_000, $this->assess($s)->required, 0.01, 'floor wins over 8M × 1.2 = 9.6M');

        $this->spend($s, 2_000_000, '2026-11-04 09:00');
        $this->assertEqualsWithDelta(12_000_000, $this->assess($s)->required, 0.01, 'forecast 10M × 1.2 rises above the floor');
    }

    public function test_nothing_is_watched_without_a_floor_or_any_spend(): void
    {
        $this->at('2026-11-04 12:00');

        $this->assertNull($this->assess($this->supplier()));
    }

    public function test_a_manual_supplier_is_not_assessed(): void
    {
        $s = $this->supplier(['low_balance_threshold' => '1'], ['slug' => 'manual-x', 'driver' => 'manual', 'currency' => 'MYR']);

        $this->assertNull($this->assess($s));
    }

    public function test_the_balance_compared_is_the_lower_of_polled_and_ledger(): void
    {
        $this->at('2026-11-04 12:00');
        $s = $this->supplier(['low_balance_threshold' => '10000000'], ['balance' => 15_000_000]);
        $this->topup($s, 15_000_000);
        $this->spend($s, 6_000_000, '2026-11-04 10:00'); // the ledger saw it, the poll has not yet

        $buffer = $this->assess($s);

        $this->assertEqualsWithDelta(9_000_000, $buffer->balance, 0.01);
        $this->assertTrue($buffer->isShort());
    }

    public function test_one_email_per_malaysia_day_while_short_and_none_on_recovery(): void
    {
        $this->at('2026-11-04 09:00');
        $s = $this->supplier(['low_balance_threshold' => '30000000']);
        $alert = app(SupplierBufferAlert::class);

        $alert->check($s->fresh());
        $alert->check($s->fresh());
        $this->alerter()->shouldHaveReceived('alert')->once();
        $this->assertNotNull($s->fresh()->low_balance_alerted_at);

        $this->at('2026-11-05 08:00'); // next Malaysia day, still short: a reminder
        $alert->check($s->fresh());
        $this->alerter()->shouldHaveReceived('alert')->twice();

        $s->update(['balance' => 50_000_000]); // topped up
        $alert->check($s->fresh());
        $this->alerter()->shouldHaveReceived('alert')->twice();
        $this->assertNull($s->fresh()->low_balance_alerted_at, 'recovery clears the claim, sends nothing');
    }

    public function test_a_claim_already_taken_today_by_another_check_sends_nothing(): void
    {
        $this->at('2026-11-04 09:00');
        $s = $this->supplier(['low_balance_threshold' => '30000000']);
        $stale = $s->fresh();
        $s->update(['low_balance_alerted_at' => now()]); // a concurrent check claimed it first

        app(SupplierBufferAlert::class)->check($stale);

        $this->alerter()->shouldNotHaveReceived('alert');
    }

    public function test_a_drawdown_and_a_polled_balance_both_queue_a_check(): void
    {
        Queue::fake();
        $s = $this->supplier();

        $s->recordPolledBalance(123.0, ['last_test_result' => 'success']);
        $this->assertEquals(123.0, (float) $s->fresh()->balance);
        Queue::assertPushed(CheckSupplierBufferJob::class, 1);

        $s->recordPolledBalance(124.0);
        Queue::assertPushed(CheckSupplierBufferJob::class, 1, 'one pending check per supplier is enough');

        $other = $this->supplier([], ['slug' => 'other', 'name' => 'Other']);
        $order = Order::factory()->create(['supplier_id' => $other->id]);
        app(SupplierFundingService::class)->recordOrderDrawdown($order, 5000.0);
        Queue::assertPushed(CheckSupplierBufferJob::class, fn ($job) => $job->supplierId === $other->id);
    }

    public function test_the_daily_refresh_checks_every_active_supplier(): void
    {
        $this->at('2026-11-04 08:00');
        $s = $this->supplier(['low_balance_threshold' => '30000000']);
        $this->app->bind('supplier-adapter.digiflazz', fn () => new class implements \App\Services\Supplier\SupplierAdapter
        {
            public function checkBalance(): \App\Services\Supplier\SupplierResponse
            {
                return \App\Services\Supplier\SupplierResponse::success(['balance' => 20_000_000]);
            }

            public function listProducts(): \App\Services\Supplier\SupplierResponse
            {
                return \App\Services\Supplier\SupplierResponse::success([]);
            }

            public function createOrder(\App\Services\Supplier\SupplierOrderRequest $request): \App\Services\Supplier\SupplierResponse
            {
                return \App\Services\Supplier\SupplierResponse::success([]);
            }

            public function checkStatus(\App\Services\Supplier\SupplierStatusCheckRequest $request): \App\Services\Supplier\SupplierResponse
            {
                return \App\Services\Supplier\SupplierResponse::success([]);
            }

            public function validatePlayer(string $playerId, ?string $serverId): \App\Services\Supplier\SupplierResponse
            {
                return \App\Services\Supplier\SupplierResponse::success([]);
            }
        });
        // fully configured for the sweep's own gate
        $s->update(['api_config' => ['base_url' => 'x', 'username' => 'u', 'api_key' => 'k', 'testing' => false, 'customer_no_separator' => '|', 'low_balance_threshold' => '30000000']]);

        $this->artisan('app:refresh-supplier-balances')->assertSuccessful();

        $this->alerter()->shouldHaveReceived('alert')->once();
    }

    public function test_the_dashboard_chip_and_the_buffer_endpoint_read_the_same_assessment(): void
    {
        $this->at('2026-11-04 12:00');
        $s = $this->supplier(['low_balance_threshold' => '1000000'], ['balance' => 5_000_000]);
        $this->spend($s, 7_000_000, '2026-11-03 10:00');
        $this->spend($s, 100_000, '2026-11-04 10:00'); // forecast 7.1M (3–4 Nov) > balance 5M > floor 1M; no TOPUP, so the ledger is not in use

        $admin = AdminUser::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'sanctum');

        $health = $this->getJson('/api/dashboard/health')->assertOk();
        $row = collect($health->json('suppliers'))->firstWhere('id', $s->id);
        $this->assertTrue($row['low_balance'], 'below the forecast although above the floor');

        $this->getJson('/api/dashboard/supplier-buffers')->assertOk()
            ->assertJsonPath('0.supplier_id', $s->id)
            ->assertJsonPath('0.is_short', true)
            ->assertJsonPath('0.window_days', 2)
            ->assertJsonPath('0.busiest_from', '2026-11-03')
            ->assertJsonPath('0.covers_until', '2026-11-05');
    }
}
