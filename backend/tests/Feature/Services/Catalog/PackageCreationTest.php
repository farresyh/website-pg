<?php

namespace Tests\Feature\Services\Catalog;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Catalog\PackageActivation;
use App\Services\Supplier\SupplierPackageStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-138 decisions 16-17: a new package is created off and goes on sale only
 * through PackageActivation, so every blocker applies at creation too. No
 * blocker means it goes on, as before. A blocker leaves it off with a reason
 * that has its own way back, and the response says why.
 */
class PackageCreationTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
        $this->game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
    }

    private function supplier(array $overrides = []): Supplier
    {
        return Supplier::query()->create(array_merge([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => ['base_url' => 'https://example.test'], 'currency' => 'MYR',
        ], $overrides));
    }

    private function product(Supplier $supplier, array $overrides = []): SupplierProduct
    {
        return SupplierProduct::query()->create(array_merge([
            'supplier_id' => $supplier->id, 'game_id' => $this->game->id, 'external_ref' => 'mlbb:86', 'name' => '86 Diamonds',
            'group_label' => 'Mobile Legends', 'price_sen' => 1000, 'status_raw' => 'active', 'last_synced_at' => now(),
        ], $overrides));
    }

    private function promote(SupplierProduct $product, array $body = [])
    {
        return $this->postJson("/api/middleware/supplier-products/{$product->id}/promote", ['name' => '86 Diamonds', ...$body]);
    }

    private function componentPackage(Supplier $supplier, string $ref, array $overrides = []): Package
    {
        return Package::query()->create(array_merge([
            'game_id' => $this->game->id, 'name' => "Component {$ref}", 'denomination' => 86, 'cost_price' => 1000,
            'standard_selling_price' => 1150, 'markup_percent' => 15, 'is_active' => true,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => $ref,
        ], $overrides));
    }

    public function test_promote_with_no_blocker_goes_on_sale_and_carries_no_notice(): void
    {
        $response = $this->promote($this->product($this->supplier()))->assertCreated();

        $this->assertNull($response->json('notice'));
        $package = Package::query()->firstOrFail();
        $this->assertTrue($package->is_active);
        $this->assertNull($package->deactivated_reason);
    }

    public function test_promote_under_a_switched_off_supplier_stays_off_and_says_why(): void
    {
        $response = $this->promote($this->product($this->supplier(['is_active' => false])))->assertCreated();

        $package = Package::query()->firstOrFail();
        $this->assertFalse($package->is_active);
        $this->assertSame(SupplierPackageStatusService::REASON, $package->deactivated_reason);
        $this->assertSame(PackageActivation::explain(SupplierPackageStatusService::REASON), $response->json('notice'));
    }

    public function test_promote_from_an_unfunded_supplier_stays_off(): void
    {
        $this->promote($this->product($this->supplier(['currency' => 'IDR'])))->assertCreated();

        $package = Package::query()->firstOrFail();
        $this->assertFalse($package->is_active);
        $this->assertSame(SupplierPackageStatusService::UNFUNDED, $package->deactivated_reason);
    }

    public function test_promote_of_a_product_the_supplier_no_longer_lists_stays_off(): void
    {
        $this->promote($this->product($this->supplier(), ['status_raw' => 'inactive']))->assertCreated();

        $package = Package::query()->firstOrFail();
        $this->assertFalse($package->is_active);
        $this->assertSame(PackageActivation::SUPPLIER_SYNC, $package->deactivated_reason);
    }

    public function test_promote_takes_the_game_from_the_product_not_the_request(): void
    {
        $other = Game::query()->create(['name' => 'Free Fire', 'slug' => 'free-fire']);

        $this->promote($this->product($this->supplier()), ['game_id' => $other->id])->assertCreated();

        $this->assertSame($this->game->id, Package::query()->firstOrFail()->game_id);
    }

    public function test_promote_refuses_a_product_whose_group_has_no_game(): void
    {
        $this->promote($this->product($this->supplier(), ['game_id' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('supplier_product');

        $this->assertSame(0, Package::query()->count());
    }

    public function test_a_combo_of_active_components_goes_on_sale_priced(): void
    {
        $supplier = $this->supplier();
        $component = $this->componentPackage($supplier, 'A');

        $response = $this->postJson("/api/games/{$this->game->id}/packages/combo", [
            'name' => 'Combo', 'components' => [['package_id' => $component->id, 'quantity' => 2]],
        ])->assertCreated();

        $this->assertNull($response->json('notice'));
        $this->assertTrue($response->json('is_active'));
        $combo = Package::query()->where('is_combo', true)->firstOrFail();
        $this->assertTrue($combo->is_active);
        $this->assertSame(2000, $combo->cost_price);
    }

    public function test_a_combo_with_an_off_component_stays_off_and_says_why(): void
    {
        $supplier = $this->supplier();
        $on = $this->componentPackage($supplier, 'A');
        $off = $this->componentPackage($supplier, 'B', ['is_active' => false, 'deactivated_reason' => PackageActivation::ADMIN]);

        $response = $this->postJson("/api/games/{$this->game->id}/packages/combo", [
            'name' => 'Combo', 'components' => [['package_id' => $on->id, 'quantity' => 1], ['package_id' => $off->id, 'quantity' => 1]],
        ])->assertCreated();

        $combo = Package::query()->where('is_combo', true)->firstOrFail();
        $this->assertFalse($combo->is_active);
        $this->assertSame(PackageActivation::COMBO_COMPONENT, $combo->deactivated_reason);
        $this->assertSame(2000, $combo->cost_price, 'priced even while off, so it can come back');
        $this->assertSame(PackageActivation::explain(PackageActivation::COMBO_COMPONENT), $response->json('notice'));
    }

    private function manualPackage(Supplier $supplier)
    {
        $game = Game::query()->create([
            'name' => 'Roblox', 'slug' => 'roblox', 'delivery_mode' => 'manual', 'delivery_subtext' => 'Within 30 minutes',
            'validation_rules' => ['credential_fields' => [['key' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true]]],
        ]);

        return $this->postJson("/api/games/{$game->id}/packages/manual", [
            'name' => '800 Robux', 'cost_price' => 4000, 'markup_percent' => 15, 'supplier_id' => $supplier->id,
        ]);
    }

    public function test_a_manual_package_goes_on_sale_and_stays_off_under_a_switched_off_supplier(): void
    {
        $on = $this->supplier(['driver' => 'manual', 'api_config' => [], 'slug' => 'roblox-a']);

        $this->assertNull($this->manualPackage($on)->assertCreated()->json('notice'));
        $this->assertTrue(Package::query()->firstOrFail()->is_active);

        Package::query()->delete();
        Game::query()->where('slug', 'roblox')->delete();
        $on->update(['is_active' => false]);

        $response = $this->manualPackage($on)->assertCreated();

        $package = Package::query()->firstOrFail();
        $this->assertFalse($package->is_active);
        $this->assertSame(SupplierPackageStatusService::REASON, $package->deactivated_reason);
        $this->assertSame("MANUAL-{$package->id}", $package->supplier_package_ref);
        $this->assertNotNull($response->json('notice'));
    }
}
