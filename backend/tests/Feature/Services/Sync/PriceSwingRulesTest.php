<?php

namespace Tests\Feature\Services\Sync;

use App\Models\AdminUser;
use App\Models\DeactivationLog;
use App\Models\Game;
use App\Models\Package;
use App\Models\PendingPriceChange;
use App\Models\PriceChangeLog;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Backup\BackupFailureAlerter;
use App\Services\Catalog\PackageActivation;
use App\Services\Sync\PackagePriceSyncResult;
use App\Services\Sync\PackagePriceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * ADR-138 decisions 6–15: a rise applies at once; a drop of 30% or more is
 * held and keeps selling at the old cost; a swing never switches a package
 * off; more than 10 packages swinging at once in one run means bad data.
 */
class PriceSwingRulesTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier = Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR',
        ]);
        $this->game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
    }

    private function package(string $ref = 'GV1', array $overrides = []): Package
    {
        return Package::query()->create(array_merge([
            'game_id' => $this->game->id, 'name' => $ref, 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'markup_percent' => 15, 'is_active' => true, 'supplier_id' => $this->supplier->id, 'supplier_package_ref' => $ref,
        ], $overrides));
    }

    private function comboOf(Package $component): Package
    {
        $combo = Package::query()->create([
            'game_id' => $this->game->id, 'name' => 'Combo', 'is_combo' => true, 'is_active' => true,
            'denomination' => 14, 'cost_price' => 1000, 'standard_selling_price' => 1150, 'markup_percent' => 0,
        ]);
        $combo->components()->attach($component->id, ['quantity' => 1, 'sort_order' => 0]);

        return $combo;
    }

    /** @param array<string, int> $prices ref => price this run; a ref left out isn't seen */
    private function sync(array $prices, string $status = 'active'): PackagePriceSyncResult
    {
        $syncedAt = now();
        foreach ($prices as $ref => $price) {
            SupplierProduct::query()->updateOrCreate(
                ['supplier_id' => $this->supplier->id, 'external_ref' => $ref],
                ['name' => $ref, 'price_sen' => $price, 'status_raw' => $status, 'last_synced_at' => $syncedAt],
            );
        }

        return app(PackagePriceSyncService::class)->apply($this->supplier, $syncedAt, priceSyncRunId: null);
    }

    private function row(): PendingPriceChange
    {
        return PendingPriceChange::query()->latest('id')->firstOrFail();
    }

    public function test_a_rise_of_any_size_applies_at_once_and_reprices_its_combo(): void
    {
        $package = $this->package();
        $combo = $this->comboOf($package);

        $this->sync(['GV1' => 5000]);

        $package->refresh();
        $this->assertSame(5000, $package->cost_price);
        $this->assertSame(5750, $package->standard_selling_price);
        $this->assertTrue($package->is_active);
        $this->assertSame(5000, $combo->refresh()->cost_price);
        $this->assertTrue($combo->is_active);
        $this->assertSame(0, PendingPriceChange::query()->count());
    }

    public function test_a_drop_of_30_percent_is_held_and_the_package_keeps_selling_at_the_old_cost(): void
    {
        $package = $this->package();

        $result = $this->sync(['GV1' => 700]);

        $this->assertSame(1, $result->anomaliesFlagged);
        $package->refresh();
        $this->assertSame(1000, $package->cost_price);
        $this->assertTrue($package->is_active);
        $this->assertNull($package->deactivated_reason);
        $this->assertSame(0, DeactivationLog::query()->count());
        $this->assertSame(0, PriceChangeLog::query()->count());
        $this->assertSame('pending', $this->row()->status);
        $this->assertSame(700, $this->row()->proposed_cost_price);
    }

    public function test_a_drop_under_30_percent_applies_as_normal(): void
    {
        $package = $this->package();

        $this->sync(['GV1' => 701]);

        $this->assertSame(701, $package->refresh()->cost_price);
        $this->assertSame(0, PendingPriceChange::query()->count());
    }

    public function test_the_hold_keeps_the_highest_price_seen(): void
    {
        $this->package();

        $this->sync(['GV1' => 500]);
        $this->sync(['GV1' => 650]);
        $this->assertSame(650, $this->row()->proposed_cost_price);

        $this->sync(['GV1' => 100]);
        $this->assertSame(650, $this->row()->proposed_cost_price);
        $this->assertSame(1, PendingPriceChange::query()->count());
    }

    /** The usual end of a false drop: `price_sen === cost_price` must not skip the row. */
    public function test_a_price_back_at_exactly_the_held_cost_closes_the_row(): void
    {
        $package = $this->package();
        $this->sync(['GV1' => 500]);

        $this->sync(['GV1' => 1000]);

        $this->assertSame('superseded', $this->row()->status);
        $this->assertSame(1000, $package->refresh()->cost_price);
    }

    public function test_a_price_back_in_range_closes_the_row_and_applies(): void
    {
        $package = $this->package();
        $this->sync(['GV1' => 500]);

        $this->sync(['GV1' => 950]);

        $this->assertSame('superseded', $this->row()->status);
        $this->assertSame(950, $package->refresh()->cost_price);
    }

    public function test_a_rise_during_a_hold_closes_the_row_and_applies(): void
    {
        $package = $this->package();
        $this->sync(['GV1' => 500]);

        $this->sync(['GV1' => 1400]);

        $this->assertSame('superseded', $this->row()->status);
        $this->assertSame(1400, $package->refresh()->cost_price);
    }

    public function test_a_held_drop_applies_the_highest_price_seen_after_24_hours(): void
    {
        $package = $this->package();
        $combo = $this->comboOf($package);
        $this->sync(['GV1' => 600]);

        $this->travel(23)->hours();
        $this->sync(['GV1' => 300]);
        $this->assertSame(1000, $package->refresh()->cost_price);

        $this->travel(1)->hours();
        $this->sync(['GV1' => 300]);

        $this->assertSame('auto_applied', PendingPriceChange::query()->oldest('id')->firstOrFail()->status);
        $this->assertSame(600, $package->refresh()->cost_price);
        $this->assertSame(690, $package->standard_selling_price);
        $this->assertSame(600, $combo->refresh()->cost_price);
        $this->assertTrue($package->is_active);
    }

    public function test_a_product_gone_for_a_run_closes_the_row_so_the_clock_restarts(): void
    {
        $package = $this->package();
        $this->sync(['GV1' => 500]);

        $this->travel(30)->hours();
        $this->sync([]);
        $this->assertSame('superseded', $this->row()->status);

        $package->update(['is_active' => true, 'deactivated_reason' => null]);
        $this->sync(['GV1' => 500]);

        $this->assertSame(1000, $package->refresh()->cost_price);
        $this->assertSame('pending', $this->row()->status);
    }

    public function test_a_product_gone_inactive_closes_the_row_and_opens_no_new_one(): void
    {
        $package = $this->package();
        $this->sync(['GV1' => 500]);

        $this->travel(30)->hours();
        $this->sync(['GV1' => 500], 'inactive');

        $this->assertSame('superseded', $this->row()->status);
        $this->assertSame(1, PendingPriceChange::query()->count());
        $this->assertSame(1000, $package->refresh()->cost_price);
    }

    public function test_the_floor_check_still_rejects_a_zero_price(): void
    {
        $package = $this->package();

        $result = $this->sync(['GV1' => 0]);

        $this->assertSame(1, $result->floorRejected);
        $this->assertSame(1000, $package->refresh()->cost_price);
        $this->assertSame(0, PendingPriceChange::query()->count());
    }

    public function test_each_supplier_sets_its_own_hold_threshold_and_hours(): void
    {
        $this->supplier->update(['api_config' => ['price_drop_hold_pct' => '20', 'price_hold_hours' => '2']]);
        $package = $this->package();

        $this->sync(['GV1' => 800]);
        $this->assertSame('pending', $this->row()->status);

        $this->travel(2)->hours();
        $this->sync(['GV1' => 800]);

        $this->assertSame('auto_applied', $this->row()->status);
        $this->assertSame(800, $package->refresh()->cost_price);
    }

    /** @return array<string, int> */
    private function massPackages(int $count, int $price): array
    {
        $prices = [];
        for ($i = 1; $i <= $count; $i++) {
            $this->package("GV-{$i}");
            $prices["GV-{$i}"] = $price;
        }

        return $prices;
    }

    private function expectAlerts(int $times): void
    {
        $alerter = Mockery::mock(BackupFailureAlerter::class);
        $alerter->shouldReceive('alert')->times($times);
        $this->app->instance(BackupFailureAlerter::class, $alerter);
    }

    public function test_more_than_10_packages_swinging_at_once_holds_rises_too_and_emails_once_a_day(): void
    {
        $this->expectAlerts(1);
        $prices = $this->massPackages(11, 2000);

        $this->sync($prices);
        $this->sync($prices);

        $this->assertSame(11, PendingPriceChange::query()->where('status', 'pending')->count());
        $this->assertSame(0, Package::query()->where('cost_price', 2000)->count());
        $this->assertSame(11, Package::query()->where('is_active', true)->count());
        $this->assertSame(2000, $this->row()->proposed_cost_price);
    }

    public function test_exactly_10_packages_swinging_is_not_a_mass_change(): void
    {
        $this->expectAlerts(0);

        $this->sync($this->massPackages(10, 2000));

        $this->assertSame(10, Package::query()->where('cost_price', 2000)->count());
    }

    public function test_a_mass_change_suspends_the_24_hour_apply_and_lets_small_changes_through(): void
    {
        $this->expectAlerts(2);
        $small = $this->package('GV-SMALL');
        $prices = $this->massPackages(11, 500);

        $this->sync($prices + ['GV-SMALL' => 1010]);
        $this->assertSame(1010, $small->refresh()->cost_price);

        $this->travel(25)->hours();
        $this->sync($prices + ['GV-SMALL' => 1010]);

        $this->assertSame(11, PendingPriceChange::query()->where('status', 'pending')->count());
        $this->assertSame(0, Package::query()->where('cost_price', 500)->count());
    }

    public function test_a_corrected_mass_change_closes_every_row(): void
    {
        $this->expectAlerts(1);
        $prices = $this->massPackages(11, 500);
        $this->sync($prices);

        $this->sync(array_map(fn () => 1000, $prices));

        $this->assertSame(11, PendingPriceChange::query()->where('status', 'superseded')->count());
        $this->assertSame(11, Package::query()->where('cost_price', 1000)->count());
    }

    public function test_the_mass_limit_is_set_per_supplier(): void
    {
        $this->expectAlerts(1);
        $this->supplier->update(['api_config' => ['mass_swing_limit' => '2']]);

        $this->sync($this->massPackages(3, 2000));

        $this->assertSame(3, PendingPriceChange::query()->where('status', 'pending')->count());
    }

    /** ADR-138 build order: a swing flagged by the old rule, still open at release. */
    public function test_a_package_the_old_rule_switched_off_comes_back_when_its_row_closes(): void
    {
        $package = $this->package('GV1', ['is_active' => false, 'deactivated_reason' => PackageActivation::PRICE_ANOMALY, 'cost_price' => 479]);
        $combo = $this->comboOf($package);
        $combo->update(['is_active' => false, 'deactivated_reason' => PackageActivation::COMBO_COMPONENT]);
        PendingPriceChange::query()->create([
            'package_id' => $package->id, 'old_cost_price' => 479, 'proposed_cost_price' => 949,
            'old_standard_selling_price' => 551, 'proposed_standard_selling_price' => 1091, 'status' => 'pending',
        ]);

        $this->sync(['GV1' => 935]);

        $package->refresh();
        $this->assertSame(935, $package->cost_price);
        $this->assertTrue($package->is_active);
        $this->assertSame('superseded', $this->row()->status);
        $combo->refresh();
        $this->assertTrue($combo->is_active);
        $this->assertSame(935, $combo->cost_price);
    }

    public function test_an_admin_can_approve_a_held_drop_early_and_every_row_of_a_supplier_at_once(): void
    {
        $this->actingAsSteppedUp(AdminUser::factory()->create(['role' => 'super_admin']));
        $this->expectAlerts(1);
        $prices = $this->massPackages(11, 500);
        $this->sync($prices);

        $other = Supplier::query()->create(['name' => 'Other', 'slug' => 'other', 'api_config' => [], 'currency' => 'MYR']);
        $otherPackage = $this->package('OTHER', ['supplier_id' => $other->id]);
        $otherRow = PendingPriceChange::query()->create([
            'package_id' => $otherPackage->id, 'old_cost_price' => 1000, 'proposed_cost_price' => 500,
            'old_standard_selling_price' => 1150, 'proposed_standard_selling_price' => 575, 'status' => 'pending',
        ]);

        $one = PendingPriceChange::query()->where('package_id', Package::query()->where('supplier_package_ref', 'GV-1')->value('id'))->firstOrFail();
        $this->patchJson("/api/middleware/price-sync/pending-price-changes/{$one->id}/approve")->assertOk();
        $this->assertSame('approved', $one->refresh()->status);
        $this->assertSame(500, $one->package->refresh()->cost_price);

        $this->postJson('/api/middleware/price-sync/pending-price-changes/approve-all', ['supplier_id' => $this->supplier->id])
            ->assertOk()->assertJson(['approved' => 10]);

        $this->assertSame(11, Package::query()->where('supplier_id', $this->supplier->id)->where('cost_price', 500)->count());
        $this->assertSame('pending', $otherRow->refresh()->status);
    }

    public function test_dismiss_no_longer_exists(): void
    {
        $this->actingAsSteppedUp(AdminUser::factory()->create(['role' => 'super_admin']));
        $this->package();
        $this->sync(['GV1' => 500]);

        $this->patchJson("/api/middleware/price-sync/pending-price-changes/{$this->row()->id}/dismiss")->assertNotFound();
    }

    public function test_an_open_row_no_longer_blocks_switching_a_package_on(): void
    {
        $package = $this->package();
        $this->sync(['GV1' => 500]);
        $package->update(['is_active' => false, 'deactivated_reason' => PackageActivation::ADMIN]);
        SupplierProduct::query()->where('external_ref', 'GV1')->update(['last_synced_at' => now()]);

        $this->assertTrue(app(PackageActivation::class)->activate($package, null));
    }
}
