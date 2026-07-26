<?php

namespace Tests\Feature\Services\Sync;

use App\Models\Game;
use App\Models\Package;
use App\Models\PriceChangeLog;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Pricing\PackageMarkupService;
use App\Services\Sync\PackagePriceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PackagePriceSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function supplier(): Supplier
    {
        return Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR',
        ]);
    }

    private function game(): Game
    {
        return Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
    }

    private function package(Supplier $supplier, Game $game, array $overrides = []): Package
    {
        return Package::query()->create(array_merge([
            'game_id' => $game->id,
            'name' => '14 Diamond',
            'cost_price' => 1000,
            'reseller_cost_price' => 1150,
            'markup_percent' => 15,
            'is_active' => true,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'GV733',
        ], $overrides));
    }

    private function rawProduct(Supplier $supplier, Carbon $syncedAt, array $overrides = []): SupplierProduct
    {
        return SupplierProduct::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'external_ref' => 'GV733',
            'name' => '14 Diamond',
            'price_sen' => 1000,
            'status_raw' => 'active',
            'last_synced_at' => $syncedAt,
        ], $overrides));
    }

    private function service(): PackagePriceSyncService
    {
        return new PackagePriceSyncService(new PackageMarkupService());
    }

    public function test_propagates_a_price_increase_and_recomputes_reseller_cost_price(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($supplier, $game, ['cost_price' => 1000, 'reseller_cost_price' => 1150, 'markup_percent' => 15]);
        $syncedAt = now();
        $this->rawProduct($supplier, $syncedAt, ['price_sen' => 1200]);

        $result = $this->service()->apply($supplier, $syncedAt, priceSyncRunId: null);

        $this->assertSame(1, $result->priceChanged);
        $this->assertSame(0, $result->deactivated);
        $this->assertSame([$game->id], $result->affectedGameIds);

        $package->refresh();
        $this->assertSame(1200, $package->cost_price);
        $this->assertSame(1380, $package->reseller_cost_price); // round(1200 * 1.15)

        $log = PriceChangeLog::query()->firstOrFail();
        $this->assertSame($package->id, $log->package_id);
        $this->assertSame(1000, $log->old_cost_price);
        $this->assertSame(1200, $log->new_cost_price);
        $this->assertSame(1150, $log->old_reseller_cost_price);
        $this->assertSame(1380, $log->new_reseller_cost_price);
    }

    /**
     * ADR-015 decision #2: founder explicitly rejected an up/down
     * asymmetry for price — a decrease applies exactly like an
     * increase, no approval gate either direction.
     */
    public function test_propagates_a_price_decrease_the_same_as_an_increase(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($supplier, $game, ['cost_price' => 1000, 'reseller_cost_price' => 1150]);
        $syncedAt = now();
        $this->rawProduct($supplier, $syncedAt, ['price_sen' => 800]);

        $result = $this->service()->apply($supplier, $syncedAt, priceSyncRunId: null);

        $this->assertSame(1, $result->priceChanged);
        $package->refresh();
        $this->assertSame(800, $package->cost_price);
        $this->assertSame(920, $package->reseller_cost_price); // round(800 * 1.15)
    }

    public function test_propagates_price_even_when_the_package_is_already_inactive(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($supplier, $game, [
            'cost_price' => 1000, 'is_active' => false, 'deactivated_reason' => 'admin', 'deactivated_at' => now(),
        ]);
        $syncedAt = now();
        $this->rawProduct($supplier, $syncedAt, ['price_sen' => 1200, 'status_raw' => 'active']);

        $result = $this->service()->apply($supplier, $syncedAt, priceSyncRunId: null);

        $this->assertSame(1, $result->priceChanged);
        $this->assertSame(1200, $package->refresh()->cost_price);
        // Admin-deactivated stays exactly as the admin left it.
        $this->assertFalse($package->is_active);
        $this->assertSame('admin', $package->deactivated_reason);
    }

    public function test_does_not_log_or_touch_the_package_when_price_is_unchanged(): void
    {
        $supplier = $this->supplier();
        $package = $this->package($supplier, $this->game(), ['cost_price' => 1000]);
        $syncedAt = now();
        $this->rawProduct($supplier, $syncedAt, ['price_sen' => 1000]);

        $result = $this->service()->apply($supplier, $syncedAt, priceSyncRunId: null);

        $this->assertSame(0, $result->priceChanged);
        $this->assertSame(0, PriceChangeLog::query()->count());
    }

    public function test_deactivates_a_package_whose_supplier_item_reports_inactive(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($supplier, $game, ['is_active' => true]);
        $syncedAt = now();
        $this->rawProduct($supplier, $syncedAt, ['status_raw' => 'inactive']);

        $result = $this->service()->apply($supplier, $syncedAt, priceSyncRunId: null);

        $this->assertSame(1, $result->deactivated);
        $package->refresh();
        $this->assertFalse($package->is_active);
        $this->assertSame('supplier_sync', $package->deactivated_reason);
        $this->assertNotNull($package->deactivated_at);
    }

    /**
     * ADR-015 decision #3: "goes inactive, or disappears entirely
     * from the latest full catalog sync" are treated identically — a
     * Package whose supplier item wasn't touched this run (stale
     * last_synced_at, or no row at all) is exactly as unfulfillable
     * as one explicitly marked inactive.
     */
    public function test_deactivates_a_package_whose_supplier_item_disappeared_from_the_latest_sync(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($supplier, $game, ['is_active' => true]);
        $staleSyncedAt = now()->subMinutes(10);
        $this->rawProduct($supplier, $staleSyncedAt); // not touched by this run

        $result = $this->service()->apply($supplier, now(), priceSyncRunId: null);

        $this->assertSame(1, $result->deactivated);
        $package->refresh();
        $this->assertFalse($package->is_active);
        $this->assertSame('supplier_sync', $package->deactivated_reason);
        $this->assertSame(0, PriceChangeLog::query()->count()); // no price to trust here
    }

    public function test_deactivates_a_package_with_no_matching_supplier_product_row_at_all(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($supplier, $game, ['is_active' => true]);
        // No SupplierProduct row created at all for this ref.

        $result = $this->service()->apply($supplier, now(), priceSyncRunId: null);

        $this->assertSame(1, $result->deactivated);
        $this->assertFalse($package->refresh()->is_active);
    }

    /**
     * ADR-015 decision #4: an admin's own on/off decision is never
     * touched by Deactivation Detection.
     */
    public function test_never_touches_a_package_the_admin_already_deactivated(): void
    {
        $supplier = $this->supplier();
        $package = $this->package($supplier, $this->game(), [
            'is_active' => false, 'deactivated_reason' => 'admin', 'deactivated_at' => now()->subDay(),
        ]);
        $this->rawProduct($supplier, now(), ['status_raw' => 'inactive']);

        $result = $this->service()->apply($supplier, now(), priceSyncRunId: null);

        $this->assertSame(0, $result->deactivated);
        $package->refresh();
        $this->assertSame('admin', $package->deactivated_reason);
    }

    /**
     * A package already deactivated by a previous sync run must not
     * be re-counted as newly deactivated on a later run.
     */
    public function test_does_not_recount_an_already_supplier_sync_deactivated_package(): void
    {
        $supplier = $this->supplier();
        $deactivatedAt = now()->subDay();
        $package = $this->package($supplier, $this->game(), [
            'is_active' => false, 'deactivated_reason' => 'supplier_sync', 'deactivated_at' => $deactivatedAt,
        ]);
        $this->rawProduct($supplier, now(), ['status_raw' => 'inactive']);

        $result = $this->service()->apply($supplier, now(), priceSyncRunId: null);

        $this->assertSame(0, $result->deactivated);
        $this->assertSame($deactivatedAt->timestamp, $package->refresh()->deactivated_at->timestamp);
    }

    /**
     * Reactivation is never automatic here (that's the Pending
     * Reactivation queue, computed elsewhere) — a supplier item back
     * to "active" leaves a previously-deactivated package untouched.
     */
    public function test_does_not_auto_reactivate_a_package_whose_supplier_item_is_active_again(): void
    {
        $supplier = $this->supplier();
        $package = $this->package($supplier, $this->game(), [
            'is_active' => false, 'deactivated_reason' => 'supplier_sync', 'deactivated_at' => now()->subDay(),
        ]);
        $syncedAt = now();
        $this->rawProduct($supplier, $syncedAt, ['status_raw' => 'active', 'price_sen' => 1000]);

        $result = $this->service()->apply($supplier, $syncedAt, priceSyncRunId: null);

        $this->assertSame(0, $result->deactivated);
        $package->refresh();
        $this->assertFalse($package->is_active);
        $this->assertSame('supplier_sync', $package->deactivated_reason);
    }
}
