<?php

namespace Tests\Unit\Services\Supplier;

use App\Models\Supplier;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\UnsupportedSupplierException;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierAdapterFactoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ADR-046 decision 2: the real bindings now read credentials from
     * the Supplier row's api_config, not .env — these two tests need a
     * seeded row to resolve at all, matching SupplierNotConfiguredException's
     * own contract.
     */
    public function test_resolves_the_gamevion_adapter_bound_in_the_container(): void
    {
        Supplier::query()->create([
            'name' => 'Gamevion',
            'slug' => 'gamevion',
            'currency' => 'MYR',
            'api_config' => ['base_url' => 'https://api.gamevion.test', 'bearer_token' => 'test', 'api_key' => 'test', 'sandbox' => true],
        ]);

        $factory = $this->app->make(SupplierAdapterFactory::class);

        $adapter = $factory->make('gamevion');

        $this->assertInstanceOf(SupplierAdapter::class, $adapter);
    }

    /** ADR-030 — confirms the real production binding, not just a test double. */
    public function test_resolves_the_digiflazz_adapter_bound_in_the_container(): void
    {
        Supplier::query()->create([
            'name' => 'Digiflazz',
            'slug' => 'digiflazz',
            'currency' => 'IDR',
            'api_config' => ['base_url' => 'https://api.digiflazz.test', 'username' => 'test', 'api_key' => 'test', 'testing' => true, 'customer_no_separator' => '|'],
        ]);

        $factory = $this->app->make(SupplierAdapterFactory::class);

        $adapter = $factory->make('digiflazz');

        $this->assertInstanceOf(SupplierAdapter::class, $adapter);
    }

    /** ADR-046 decision 2: unconfigured (no api_config) is now a distinct failure from unbound. */
    public function test_throws_for_a_bound_but_unconfigured_supplier(): void
    {
        $factory = $this->app->make(SupplierAdapterFactory::class);

        $this->expectException(\App\Services\Supplier\SupplierNotConfiguredException::class);

        $factory->make('gamevion');
    }

    public function test_throws_for_an_unbound_supplier_slug(): void
    {
        $factory = $this->app->make(SupplierAdapterFactory::class);

        $this->expectException(UnsupportedSupplierException::class);

        $factory->make('shopeepay-topup');
    }

    /**
     * Proves the actual point of this seam: a second supplier becomes
     * resolvable the moment it's bound in the container, with no
     * change to this factory's own code — mirrors
     * PaymentGatewayFactoryTest::test_resolves_a_newly_bound_gateway_without_code_changes().
     */
    public function test_resolves_a_newly_bound_supplier_without_code_changes(): void
    {
        $fake = new class implements SupplierAdapter
        {
            public function checkBalance(): SupplierResponse
            {
                return SupplierResponse::success(['balance' => 0]);
            }

            public function listProducts(): SupplierResponse
            {
                return SupplierResponse::success([]);
            }

            public function createOrder(SupplierOrderRequest $request): SupplierResponse
            {
                return SupplierResponse::success([]);
            }

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                return SupplierResponse::success([]);
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used in this test');
            }
        };
        $this->app->bind('supplier-adapter.digiflazz', fn () => $fake);

        $factory = $this->app->make(SupplierAdapterFactory::class);

        $this->assertSame($fake, $factory->make('digiflazz'));
    }
}
