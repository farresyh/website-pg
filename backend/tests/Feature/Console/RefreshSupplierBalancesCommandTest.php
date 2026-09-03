<?php

namespace Tests\Feature\Console;

use App\Models\Supplier;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
