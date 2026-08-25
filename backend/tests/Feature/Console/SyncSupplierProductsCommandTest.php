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
        $this->app->bind('supplier-adapter.gamevion', fn () => $this->fakeAdapter($success, $items));
    }

    private function fakeAdapter(bool $success, array $items = []): SupplierAdapter
    {
        return new class($success, $items) implements SupplierAdapter
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
        };
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

    /**
     * ADR-031 decision 4: the command loops every active Supplier row
     * and resolves each one's own adapter via SupplierAdapterFactory —
     * proves it against two real suppliers with distinct catalogs, not
     * just the single Gamevion stopgap this command used to hardcode.
     */
    public function test_command_syncs_every_active_supplier_via_its_own_adapter(): void
    {
        $gamevion = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR', 'is_active' => true]);
        $digiflazz = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz-test', 'api_config' => [], 'currency' => 'IDR', 'is_active' => true]);

        $this->app->bind('supplier-adapter.gamevion', fn () => $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
        ]));
        $this->app->bind('supplier-adapter.digiflazz-test', fn () => $this->fakeAdapter(true, [
            new SupplierCatalogItem('xld10', 'MLBB 10 Diamonds', 'Mobile Legends', 3200.0, 'active'),
            new SupplierCatalogItem('xld20', 'MLBB 20 Diamonds', 'Mobile Legends', 6400.0, 'active'),
        ]));

        $this->artisan('app:sync-supplier-products')->assertExitCode(0);

        $this->assertSame(1, SupplierProduct::query()->where('supplier_id', $gamevion->id)->count());
        $this->assertSame(2, SupplierProduct::query()->where('supplier_id', $digiflazz->id)->count());
    }

    public function test_command_skips_an_inactive_supplier(): void
    {
        Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR', 'is_active' => true]);
        $inactive = Supplier::query()->create(['name' => 'Retired', 'slug' => 'retired-supplier', 'api_config' => [], 'currency' => 'MYR', 'is_active' => false]);

        $this->app->bind('supplier-adapter.gamevion', fn () => $this->fakeAdapter(true, []));
        $this->app->bind('supplier-adapter.retired-supplier', fn () => new class implements SupplierAdapter
        {
            public function checkBalance(): SupplierResponse
            {
                throw new RuntimeException('inactive supplier must never be synced');
            }

            public function listProducts(): SupplierResponse
            {
                throw new RuntimeException('inactive supplier must never be synced');
            }

            public function createOrder(SupplierOrderRequest $request): SupplierResponse
            {
                throw new RuntimeException('inactive supplier must never be synced');
            }

            public function checkStatus(string $supplierRef): SupplierResponse
            {
                throw new RuntimeException('inactive supplier must never be synced');
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used in this test');
            }
        });

        $this->artisan('app:sync-supplier-products')->assertExitCode(0);

        $this->assertSame(0, SupplierProduct::query()->where('supplier_id', $inactive->id)->count());
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
