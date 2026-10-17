<?php

namespace Tests\Feature\Console;

use App\Models\PriceSyncRun;
use App\Models\Supplier;
use App\Services\Backup\BackupFailureAlerter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * ADR-015 2026-10-17 addendum (PRD §16 item 106): one email when a
 * supplier's prices haven't synced for its threshold (default 90 min),
 * one "recovered" email when they sync again, nothing in between.
 */
class CheckPriceSyncStalenessCommandTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIG = ['base_url' => 'https://fake.test'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(BackupFailureAlerter::class, Mockery::spy(BackupFailureAlerter::class));
    }

    private function supplier(int $minutesSinceSync, array $config = self::CONFIG, bool $active = true): Supplier
    {
        return Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => $config, 'currency' => 'MYR',
            'is_active' => $active, 'last_price_sync_at' => now()->subMinutes($minutesSinceSync),
        ]);
    }

    private function alerter(): Mockery\MockInterface
    {
        return app(BackupFailureAlerter::class);
    }

    public function test_alerts_once_when_a_supplier_passes_the_default_threshold(): void
    {
        $supplier = $this->supplier(91);
        PriceSyncRun::query()->create(['status' => 'partial', 'stats' => ['failed_suppliers' => [['slug' => 'gamevion', 'error' => 'cURL error 28']]]]);

        $this->artisan('app:check-price-sync-staleness')->assertSuccessful();
        $this->artisan('app:check-price-sync-staleness')->assertSuccessful();

        $this->alerter()->shouldHaveReceived('alert')
            ->with('Gamevion price sync is stale', Mockery::on(fn (string $m) => str_contains($m, 'cURL error 28')), 'Price Sync')
            ->once();
        $this->assertNotNull($supplier->refresh()->price_sync_alerted_at);
    }

    public function test_does_not_alert_inside_the_threshold(): void
    {
        $this->supplier(89);

        $this->artisan('app:check-price-sync-staleness')->assertSuccessful();

        $this->alerter()->shouldNotHaveReceived('alert');
    }

    public function test_uses_the_suppliers_own_threshold_and_zero_turns_it_off(): void
    {
        $this->supplier(31, self::CONFIG + ['price_sync_stale_alert_minutes' => '30']);
        $this->artisan('app:check-price-sync-staleness')->assertSuccessful();
        $this->alerter()->shouldHaveReceived('alert')->once();

        Supplier::query()->delete();
        $this->supplier(10_000, self::CONFIG + ['price_sync_stale_alert_minutes' => '0']);
        $this->artisan('app:check-price-sync-staleness')->assertSuccessful();
        $this->alerter()->shouldHaveReceived('alert')->once();
    }

    public function test_sends_one_recovery_email_once_the_supplier_syncs_again(): void
    {
        $supplier = $this->supplier(200);
        $this->artisan('app:check-price-sync-staleness');

        $supplier->update(['last_price_sync_at' => now()->addSecond()]);
        $this->artisan('app:check-price-sync-staleness');
        $this->artisan('app:check-price-sync-staleness');

        $this->alerter()->shouldHaveReceived('alert')->with('Gamevion price sync recovered', Mockery::any(), 'Price Sync')->once();
        $this->assertNull($supplier->refresh()->price_sync_alerted_at);
    }

    public function test_an_open_alert_is_not_closed_by_turning_the_threshold_off(): void
    {
        $supplier = $this->supplier(200);
        $this->artisan('app:check-price-sync-staleness');

        $supplier->update(['api_config' => self::CONFIG + ['price_sync_stale_alert_minutes' => '0']]);
        $this->artisan('app:check-price-sync-staleness');

        $this->alerter()->shouldNotHaveReceived('alert', ['Gamevion price sync recovered', Mockery::any(), 'Price Sync']);
        $this->assertNotNull($supplier->refresh()->price_sync_alerted_at);
    }

    public function test_skips_inactive_and_unconfigured_suppliers(): void
    {
        $this->supplier(10_000, active: false);
        Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR', 'last_price_sync_at' => now()->subDay()]);

        $this->artisan('app:check-price-sync-staleness')->assertSuccessful();

        $this->alerter()->shouldNotHaveReceived('alert');
    }

    public function test_a_supplier_that_never_synced_is_judged_from_when_it_was_added(): void
    {
        $this->travelTo(now()->subHours(3));
        Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => self::CONFIG, 'currency' => 'MYR']);
        $this->travelBack();

        $this->artisan('app:check-price-sync-staleness')->assertSuccessful();

        $this->alerter()->shouldHaveReceived('alert')->once();
    }
}
