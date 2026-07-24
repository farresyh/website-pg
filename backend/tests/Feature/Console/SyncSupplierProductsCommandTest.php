<?php

namespace Tests\Feature\Console;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierCatalogItem;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SyncSupplierProductsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function bindFakeAdapter(bool $success, array $items = []): void
    {
        $this->app->bind(SupplierAdapter::class, fn () => new class($success, $items) implements SupplierAdapter
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

            public function checkStatus(string $supplierRef): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used in this test');
            }
        });
    }

    public function test_command_creates_the_gamevion_supplier_row_when_missing_and_syncs_products(): void
    {
        $this->bindFakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
        ]);

        $this->artisan('app:sync-supplier-products')->assertExitCode(0);

        $supplier = Supplier::query()->where('slug', 'gamevion')->firstOrFail();
        $this->assertSame(1, SupplierProduct::query()->where('supplier_id', $supplier->id)->count());
    }

    public function test_command_reuses_an_existing_gamevion_supplier_row(): void
    {
        $existing = Supplier::query()->create([
            'name' => 'Gamevion',
            'slug' => 'gamevion',
            'api_config' => [],
            'currency' => 'MYR',
        ]);
        $this->bindFakeAdapter(true, []);

        $this->artisan('app:sync-supplier-products')->assertExitCode(0);

        $this->assertSame(1, Supplier::query()->where('slug', 'gamevion')->count());
        $this->assertSame($existing->id, Supplier::query()->where('slug', 'gamevion')->firstOrFail()->id);
    }

    public function test_command_fails_loudly_when_the_adapter_call_fails(): void
    {
        $this->bindFakeAdapter(false);

        $this->artisan('app:sync-supplier-products')->assertExitCode(1);

        $this->assertSame(0, SupplierProduct::query()->count());
    }
}
