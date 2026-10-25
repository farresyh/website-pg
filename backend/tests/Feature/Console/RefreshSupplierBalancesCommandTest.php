<?php

namespace Tests\Feature\Console;

use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Services\Accounting\SupplierLedgerEntryType;
use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\Supplier\CircuitBreakingSupplierAdapter;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-069 decision 12 — the daily balance refresh: writes
 * Supplier.balance for every active, configured supplier, warns on a
 * low balance, and never lets one supplier's failure abort the sweep.
 */
class RefreshSupplierBalancesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function fakeAdapter(bool $success, ?float $balance): SupplierAdapter
    {
        return new class($success, $balance) implements SupplierAdapter
        {
            public function __construct(private readonly bool $success, private readonly ?float $balance) {}

            public function checkBalance(): SupplierResponse
            {
                return $this->success
                    ? SupplierResponse::success(['balance' => $this->balance])
                    : SupplierResponse::failure('rc45', 'IP not whitelisted', true);
            }

            public function listProducts(): SupplierResponse
            {
                throw new RuntimeException('not used');
            }

            public function createOrder(SupplierOrderRequest $request): SupplierResponse
            {
                throw new RuntimeException('not used');
            }

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                throw new RuntimeException('not used');
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used');
            }
        };
    }

    private function configuredGamevion(array $config = []): Supplier
    {
        return Supplier::query()->create([
            'name' => 'Gamevion',
            'slug' => 'gamevion',
            'is_active' => true,
            'currency' => 'MYR',
            'api_config' => array_merge(
                ['base_url' => 'https://api.gamevion.com', 'bearer_token' => 't', 'api_key' => 'k', 'sandbox' => false],
                $config,
            ),
        ]);
    }

    public function test_it_writes_the_refreshed_balance(): void
    {
        $supplier = $this->configuredGamevion();
        $this->app->bind('supplier-adapter.gamevion', fn () => $this->fakeAdapter(true, 812.50));

        $this->artisan('app:refresh-supplier-balances')->assertSuccessful();

        $this->assertEquals(812.50, $supplier->fresh()->balance);
        $this->assertSame('success', $supplier->fresh()->last_test_result);
    }

    public function test_it_warns_when_the_balance_is_below_the_threshold(): void
    {
        $this->configuredGamevion(['low_balance_threshold' => '100']);
        $this->app->bind('supplier-adapter.gamevion', fn () => $this->fakeAdapter(true, 40.0));

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $ctx) => $message === 'Supplier balance is low'
                && $ctx['supplier'] === 'gamevion'
                && $ctx['balance'] === 40.0
                && $ctx['threshold'] === 100.0);

        $this->artisan('app:refresh-supplier-balances')->assertSuccessful();
    }

    public function test_it_does_not_warn_when_no_threshold_is_set(): void
    {
        $this->configuredGamevion();
        $this->app->bind('supplier-adapter.gamevion', fn () => $this->fakeAdapter(true, 1.0));

        Log::shouldReceive('warning')->never();

        $this->artisan('app:refresh-supplier-balances')->assertSuccessful();
    }

    public function test_it_records_a_failure_without_aborting(): void
    {
        $supplier = $this->configuredGamevion();
        $this->app->bind('supplier-adapter.gamevion', fn () => $this->fakeAdapter(false, null));

        $this->artisan('app:refresh-supplier-balances')->assertSuccessful();

        $this->assertStringContainsString('failed', $supplier->fresh()->last_test_result);
    }

    /** ADR-083 decision 6: the freshly-polled balance vs the funding ledger's own sum. */
    public function test_it_warns_when_the_funding_ledger_has_drifted(): void
    {
        $supplier = $this->configuredGamevion(['drift_threshold' => '10']);
        SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id,
            'type' => SupplierLedgerEntryType::Topup->value,
            'amount' => 700.0, // polled comes back 812.50 below — variance 112.50 > threshold 10
            'currency' => 'MYR',
        ]);
        $this->app->bind('supplier-adapter.gamevion', fn () => $this->fakeAdapter(true, 812.50));

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $ctx) => $message === 'Supplier funding ledger has drifted from the polled balance'
                && $ctx['supplier'] === 'gamevion'
                && $ctx['polled_balance'] === 812.50
                && $ctx['ledger_balance'] === 700.0
                && $ctx['variance'] === 112.50
                && $ctx['threshold'] === 10.0);

        $this->artisan('app:refresh-supplier-balances')->assertSuccessful();
    }

    public function test_it_does_not_warn_on_drift_when_no_drift_threshold_is_set(): void
    {
        $supplier = $this->configuredGamevion();
        SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id,
            'type' => SupplierLedgerEntryType::Topup->value,
            'amount' => 1.0, // wildly different from the polled 812.50, but unwatched
            'currency' => 'MYR',
        ]);
        $this->app->bind('supplier-adapter.gamevion', fn () => $this->fakeAdapter(true, 812.50));

        Log::shouldReceive('warning')->never();

        $this->artisan('app:refresh-supplier-balances')->assertSuccessful();
    }

    public function test_it_skips_a_supplier_that_is_not_fully_configured(): void
    {
        Supplier::query()->create([
            'name' => 'Digiflazz', 'slug' => 'digiflazz', 'is_active' => true, 'currency' => 'IDR',
            'api_config' => ['base_url' => 'https://api.digiflazz.com'], // missing username/api_key/testing/separator
        ]);

        $this->artisan('app:refresh-supplier-balances')
            ->expectsOutputToContain('No active, fully-configured supplier to refresh.')
            ->assertSuccessful();
    }

    private function configuredDigiflazz(): Supplier
    {
        return Supplier::query()->create([
            'name' => 'Digiflazz', 'slug' => 'digiflazz', 'driver' => 'digiflazz', 'is_active' => true, 'currency' => 'IDR',
            'api_config' => ['base_url' => 'https://api.digiflazz.com', 'username' => 'u', 'api_key' => 'k', 'testing' => false, 'customer_no_separator' => '|'],
        ]);
    }

    /**
     * Item 130 (2026-10-22 prod): Gamevion refused connections and the
     * thrown ConnectionException aborted the sweep, so Digiflazz was never
     * refreshed and the CRITICAL task went red. Through the real breaker
     * decorator an unreachable supplier is an ordinary failure now.
     */
    public function test_an_unreachable_supplier_does_not_stop_the_others_or_fail_the_task(): void
    {
        $gamevion = $this->configuredGamevion();
        $digiflazz = $this->configuredDigiflazz();
        $this->app->bind('supplier-adapter.gamevion', fn () => new CircuitBreakingSupplierAdapter(
            $this->throwingAdapter(new ConnectionException('cURL error 7: Failed to connect')),
            new CircuitBreaker('test-'.uniqid(), failureThreshold: 3, cooldownSeconds: 60),
        ));
        $this->app->bind('supplier-adapter.digiflazz', fn () => $this->fakeAdapter(true, 797643.0));

        $this->artisan('app:refresh-supplier-balances')->assertSuccessful();

        $this->assertStringContainsString('CONNECTION_FAILED', $gamevion->fresh()->last_test_result);
        $this->assertEquals(797643.0, $digiflazz->fresh()->balance);
    }

    /** A bug (not an outage) in one supplier still lets the rest refresh, then fails the task so health pages. */
    public function test_an_unexpected_error_skips_only_that_supplier_and_fails_the_task(): void
    {
        $gamevion = $this->configuredGamevion();
        $digiflazz = $this->configuredDigiflazz();
        $this->app->bind('supplier-adapter.gamevion', fn () => $this->throwingAdapter(new RuntimeException('boom')));
        $this->app->bind('supplier-adapter.digiflazz', fn () => $this->fakeAdapter(true, 797643.0));

        $this->artisan('app:refresh-supplier-balances')->assertFailed();

        $this->assertStringContainsString('boom', $gamevion->fresh()->last_test_result);
        $this->assertEquals(797643.0, $digiflazz->fresh()->balance);
    }

    private function throwingAdapter(\Throwable $e): SupplierAdapter
    {
        return new class($e) implements SupplierAdapter
        {
            public function __construct(private readonly \Throwable $e) {}

            public function checkBalance(): SupplierResponse
            {
                throw $this->e;
            }

            public function listProducts(): SupplierResponse
            {
                throw new RuntimeException('not used');
            }

            public function createOrder(SupplierOrderRequest $request): SupplierResponse
            {
                throw new RuntimeException('not used');
            }

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                throw new RuntimeException('not used');
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used');
            }
        };
    }
}
