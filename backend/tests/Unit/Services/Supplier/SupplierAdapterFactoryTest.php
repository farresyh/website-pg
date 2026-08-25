<?php

namespace Tests\Unit\Services\Supplier;

use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\UnsupportedSupplierException;
use App\Services\Supplier\ValidationNotSupportedException;
use Tests\TestCase;

class SupplierAdapterFactoryTest extends TestCase
{
    public function test_resolves_the_gamevion_adapter_bound_in_the_container(): void
    {
        $factory = $this->app->make(SupplierAdapterFactory::class);

        $adapter = $factory->make('gamevion');

        $this->assertInstanceOf(SupplierAdapter::class, $adapter);
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

            public function checkStatus(string $supplierRef): SupplierResponse
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
