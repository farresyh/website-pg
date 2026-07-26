<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SyncSupplierPricesJob;
use App\Models\Game;
use App\Models\Package;
use App\Models\PriceSyncRun;
use App\Models\Supplier;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierCatalogItem;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SyncSupplierPricesJobTest extends TestCase
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

    public function test_job_runs_stage_1_and_2_and_marks_the_run_successful(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR',
        ]);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        $package = Package::query()->create([
            'game_id' => $game->id,
            'name' => '14 Diamond',
            'cost_price' => 1000,
            'reseller_cost_price' => 1150,
            'markup_percent' => 15,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'GV733',
        ]);

        $this->bindFakeAdapter(true, [
            new SupplierCatalogItem('GV733', '14 Diamond', 'Mobile Legends', 12.0, 'active'),
        ]);

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        (new SyncSupplierPricesJob($run))->handle(
            app(\App\Services\Sync\ProductSyncService::class),
            app(\App\Services\Sync\PackagePriceSyncService::class),
            app(SupplierAdapter::class),
        );

        $run->refresh();
        $this->assertSame('success', $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(1, $run->stats['price_changed']);
        $this->assertSame(0, $run->stats['deactivated']);

        $this->assertSame(1200, $package->refresh()->cost_price); // 12.0 MYR -> 1200 sen
    }

    public function test_job_marks_the_run_failed_when_the_adapter_call_fails(): void
    {
        Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR',
        ]);
        $this->bindFakeAdapter(false);

        $run = PriceSyncRun::query()->create(['status' => 'queued']);

        (new SyncSupplierPricesJob($run))->handle(
            app(\App\Services\Sync\ProductSyncService::class),
            app(\App\Services\Sync\PackagePriceSyncService::class),
            app(SupplierAdapter::class),
        );

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->error_message);
    }
}
