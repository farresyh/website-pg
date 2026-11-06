<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Models\AdminUser;
use App\Models\DeactivationLog;
use App\Models\Game;
use App\Models\Package;
use App\Models\PendingPriceChange;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Catalog\PackageActivation;
use App\Services\Supplier\SupplierPackageStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-138 decisions 20-22: an API supplier that is off has no package on sale.
 * Switching it on restores nothing, and Reactivate waits for a recent sync,
 * because `listedBySupplier` only compares products with each other and passes
 * after a long stop.
 */
class SupplierSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsSteppedUp(AdminUser::factory()->create(['role' => 'super_admin']));
        $this->game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        $this->supplier = Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'driver' => 'gamevion', 'currency' => 'MYR',
            'api_config' => ['base_url' => 'https://example.test'], 'last_price_sync_at' => now(),
        ]);
    }

    private function package(string $ref, array $overrides = []): Package
    {
        SupplierProduct::query()->create([
            'supplier_id' => $this->supplier->id, 'game_id' => $this->game->id, 'external_ref' => $ref, 'name' => $ref,
            'group_label' => 'Mobile Legends', 'price_sen' => 1000, 'status_raw' => 'active', 'last_synced_at' => now(),
        ]);

        return Package::query()->create(array_merge([
            'game_id' => $this->game->id, 'name' => "Package {$ref}", 'denomination' => 86, 'cost_price' => 1000,
            'standard_selling_price' => 1150, 'markup_percent' => 15, 'is_active' => true,
            'supplier_id' => $this->supplier->id, 'supplier_package_ref' => $ref,
        ], $overrides));
    }

    private function combo(Package $component): Package
    {
        $combo = Package::query()->create([
            'game_id' => $this->game->id, 'name' => 'Combo', 'denomination' => 86, 'cost_price' => 1000,
            'standard_selling_price' => 1150, 'markup_percent' => 15, 'is_combo' => true, 'is_active' => true,
        ]);
        $combo->components()->attach($component->id, ['quantity' => 1, 'sort_order' => 0]);

        return $combo;
    }

    private function switchTo(bool $on)
    {
        return $this->patchJson("/api/middleware/suppliers/{$this->supplier->id}/status", ['is_active' => $on]);
    }

    private function reactivate()
    {
        return $this->patchJson("/api/middleware/suppliers/{$this->supplier->id}/packages/status", ['is_active' => true]);
    }

    public function test_switching_an_api_supplier_off_takes_its_packages_and_combos_off_sale(): void
    {
        $package = $this->package('A');
        $combo = $this->combo($package);

        $this->switchTo(false)->assertOk()->assertJsonPath('is_active', false);

        $this->assertFalse($package->fresh()->is_active);
        $this->assertSame(SupplierPackageStatusService::REASON, $package->fresh()->deactivated_reason);
        $this->assertFalse($combo->fresh()->is_active);
        $this->assertSame(PackageActivation::COMBO_COMPONENT, $combo->fresh()->deactivated_reason);
        $this->assertSame(1, DeactivationLog::query()->where('package_id', $package->id)->count());
    }

    public function test_a_package_already_off_keeps_its_reason(): void
    {
        $package = $this->package('A', ['is_active' => false, 'deactivated_reason' => PackageActivation::ADMIN]);

        $this->switchTo(false)->assertOk();

        $this->assertSame(PackageActivation::ADMIN, $package->fresh()->deactivated_reason);
    }

    /** A parallel activate() must see the blocker before any package goes off. */
    public function test_the_supplier_flag_is_written_before_any_package(): void
    {
        $this->package('A');
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^update "(suppliers|packages)"/', $query->sql, $match)) {
                $writes[] = $match[1];
            }
        });

        $this->switchTo(false)->assertOk();

        $this->assertSame(['suppliers', 'packages'], array_values(array_unique($writes)));
    }

    public function test_switching_off_closes_its_open_price_rows_only(): void
    {
        $row = fn (Package $package) => PendingPriceChange::query()->create([
            'package_id' => $package->id, 'old_cost_price' => 1000, 'proposed_cost_price' => 600,
            'old_standard_selling_price' => 1150, 'proposed_standard_selling_price' => 690, 'status' => 'pending',
        ]);
        $own = $row($this->package('A'));
        $otherSupplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'MYR']);
        $other = $row(Package::query()->create([
            'game_id' => $this->game->id, 'name' => 'Other', 'cost_price' => 1000, 'standard_selling_price' => 1150, 'markup_percent' => 15,
            'is_active' => true, 'supplier_id' => $otherSupplier->id, 'supplier_package_ref' => 'B',
        ]));

        $this->switchTo(false)->assertOk();

        $this->assertSame('superseded', $own->fresh()->status);
        $this->assertSame('pending', $other->fresh()->status);
    }

    public function test_switching_an_api_supplier_on_restores_nothing_until_reactivate(): void
    {
        $package = $this->package('A');
        $this->switchTo(false)->assertOk();

        $this->switchTo(true)->assertOk()->assertJsonPath('is_active', true);
        $this->assertFalse($package->fresh()->is_active);

        $this->reactivate()->assertOk()->assertJsonPath('updated', 1);
        $this->assertTrue($package->fresh()->is_active);
    }

    public function test_reactivate_refuses_until_the_supplier_has_synced_recently(): void
    {
        $package = $this->package('A', ['is_active' => false, 'deactivated_reason' => SupplierPackageStatusService::REASON]);

        foreach ([now()->subMinutes(91), null] as $lastSync) {
            $this->supplier->update(['last_price_sync_at' => $lastSync]);
            $this->reactivate()->assertUnprocessable()->assertJsonValidationErrors('is_active');
            $this->assertFalse($package->fresh()->is_active);
        }

        $this->supplier->update(['last_price_sync_at' => now()->subMinutes(89)]);
        $this->reactivate()->assertOk();
        $this->assertTrue($package->fresh()->is_active);
    }

    public function test_reactivate_reads_the_suppliers_own_limit_and_zero_keeps_the_default(): void
    {
        $this->package('A', ['is_active' => false, 'deactivated_reason' => SupplierPackageStatusService::REASON]);
        $config = $this->supplier->api_config;

        $this->supplier->update(['api_config' => [...$config, 'price_sync_stale_alert_minutes' => '180'], 'last_price_sync_at' => now()->subMinutes(120)]);
        $this->reactivate()->assertOk();

        $this->supplier->update(['api_config' => [...$config, 'price_sync_stale_alert_minutes' => '0']]);
        $this->reactivate()->assertUnprocessable();
    }

    public function test_deactivating_packages_needs_no_recent_sync(): void
    {
        $package = $this->package('A');
        $this->supplier->update(['last_price_sync_at' => null]);

        $this->patchJson("/api/middleware/suppliers/{$this->supplier->id}/packages/status", ['is_active' => false, 'reason' => 'Outage'])->assertOk();

        $this->assertFalse($package->fresh()->is_active);
    }

    public function test_a_supplier_row_says_how_much_is_on_sale_for_the_confirm_dialog(): void
    {
        $package = $this->package('A');
        $this->package('B');
        $this->package('C', ['is_active' => false, 'deactivated_reason' => PackageActivation::ADMIN]);
        $this->combo($package);

        $row = collect($this->getJson('/api/middleware/suppliers')->assertOk()->json())->firstWhere('id', $this->supplier->id);

        $this->assertSame(['packages' => 2, 'combos' => 1], $row['on_sale']);
    }
}
