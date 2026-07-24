<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplierProductControllerTest extends TestCase
{
    use RefreshDatabase;

    private function supplier(): Supplier
    {
        return Supplier::query()->create([
            'name' => 'Gamevion',
            'slug' => 'gamevion',
            'api_config' => [],
            'currency' => 'MYR',
        ]);
    }

    private function rawProduct(Supplier $supplier, array $overrides = []): SupplierProduct
    {
        return SupplierProduct::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'external_ref' => 'GV733',
            'name' => '14 Diamond (13+1 Bonus)',
            'category_raw' => 'Mobile legends Global ',
            'price_sen' => 1164,
            'status_raw' => 'active',
            'last_synced_at' => now(),
        ], $overrides));
    }

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/middleware/supplier-products')->assertUnauthorized();
    }

    public function test_index_lists_raw_products_and_flags_already_promoted_ones(): void
    {
        $supplier = $this->supplier();
        $this->rawProduct($supplier, ['external_ref' => 'GV733', 'name' => 'Already Promoted']);
        $this->rawProduct($supplier, ['external_ref' => 'GV999', 'name' => 'Not Promoted Yet']);

        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        Package::query()->create([
            'game_id' => $game->id,
            'name' => 'Already Promoted (Final Name)',
            'cost_price' => 1164,
            'reseller_cost_price' => 1300,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'GV733',
        ]);

        $this->actingAsAdmin();

        $response = $this->getJson('/api/middleware/supplier-products');

        $response->assertOk();
        $byRef = collect($response->json('data'))->keyBy('external_ref');
        $this->assertTrue($byRef['GV733']['is_promoted']);
        $this->assertFalse($byRef['GV999']['is_promoted']);
    }

    public function test_index_can_search_by_name(): void
    {
        $supplier = $this->supplier();
        $this->rawProduct($supplier, ['external_ref' => 'A', 'name' => 'Mobile Legends 14 Diamond', 'category_raw' => 'MOBA']);
        $this->rawProduct($supplier, ['external_ref' => 'B', 'name' => 'Free Fire 5 Diamonds', 'category_raw' => 'Battle Royale']);

        $this->actingAsAdmin();

        $response = $this->getJson('/api/middleware/supplier-products?search=Mobile');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Mobile Legends 14 Diamond', $response->json('data.0.name'));
    }

    /**
     * Gamevion's raw item names are pure denominations ("100
     * Diamonds") — the game identity lives in category_raw ("Free
     * Fire Global"). An admin searching a game name must find its
     * items even though the game name never appears in `name` itself.
     */
    public function test_index_can_search_by_category_when_the_game_name_is_not_in_the_item_name(): void
    {
        $supplier = $this->supplier();
        $this->rawProduct($supplier, ['external_ref' => 'A', 'name' => '100 Diamonds', 'category_raw' => 'Free Fire Global']);
        $this->rawProduct($supplier, ['external_ref' => 'B', 'name' => '100 Diamonds', 'category_raw' => 'Mobile Legends']);

        $this->actingAsAdmin();

        $response = $this->getJson('/api/middleware/supplier-products?search=Free+Fire');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Free Fire Global', $response->json('data.0.category_raw'));
    }

    public function test_index_can_filter_by_exact_category(): void
    {
        $supplier = $this->supplier();
        $this->rawProduct($supplier, ['external_ref' => 'A', 'category_raw' => 'Free Fire Global']);
        $this->rawProduct($supplier, ['external_ref' => 'B', 'category_raw' => 'Free Fire (Malaysia)']);

        $this->actingAsAdmin();

        $response = $this->getJson('/api/middleware/supplier-products?category=' . urlencode('Free Fire Global'));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('A', $response->json('data.0.external_ref'));
    }

    public function test_categories_groups_raw_products_with_counts_and_linked_game(): void
    {
        $supplier = $this->supplier();
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $linked = $this->rawProduct($supplier, ['external_ref' => 'A', 'category_raw' => 'Free Fire Global', 'game_id' => $game->id]);
        $this->rawProduct($supplier, ['external_ref' => 'B', 'category_raw' => 'Free Fire Global', 'game_id' => $game->id]);
        $this->rawProduct($supplier, ['external_ref' => 'C', 'category_raw' => 'Mobile Legends']);

        Package::query()->create([
            'game_id' => $game->id,
            'name' => 'Promoted One',
            'cost_price' => 1164,
            'reseller_cost_price' => 1300,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => $linked->external_ref,
        ]);

        $this->actingAsAdmin();

        $response = $this->getJson('/api/middleware/supplier-products/categories');

        $response->assertOk();
        $byCategory = collect($response->json())->keyBy('category_raw');

        $this->assertSame(2, $byCategory['Free Fire Global']['total']);
        $this->assertSame(1, $byCategory['Free Fire Global']['promoted_count']);
        $this->assertSame($game->id, $byCategory['Free Fire Global']['game']['id']);

        $this->assertSame(1, $byCategory['Mobile Legends']['total']);
        $this->assertNull($byCategory['Mobile Legends']['game']);
    }

    public function test_link_category_creates_a_new_game_and_stamps_every_item_in_the_category(): void
    {
        $supplier = $this->supplier();
        $this->rawProduct($supplier, ['external_ref' => 'A', 'category_raw' => 'Free Fire Global']);
        $this->rawProduct($supplier, ['external_ref' => 'B', 'category_raw' => 'Free Fire Global']);
        $this->rawProduct($supplier, ['external_ref' => 'C', 'category_raw' => 'Mobile Legends']);
        $this->actingAsAdmin();

        $response = $this->postJson('/api/middleware/supplier-products/categories/link', [
            'category_raw' => 'Free Fire Global',
            'new_game' => ['name' => 'Free Fire Global', 'category' => 'Battle Royale'],
        ]);

        $response->assertOk();
        $game = Game::query()->where('name', 'Free Fire Global')->firstOrFail();
        $this->assertSame('Battle Royale', $game->category);

        $this->assertSame($game->id, SupplierProduct::query()->where('external_ref', 'A')->firstOrFail()->game_id);
        $this->assertSame($game->id, SupplierProduct::query()->where('external_ref', 'B')->firstOrFail()->game_id);
        $this->assertNull(SupplierProduct::query()->where('external_ref', 'C')->firstOrFail()->game_id);
    }

    public function test_link_category_links_to_an_existing_game(): void
    {
        $supplier = $this->supplier();
        $this->rawProduct($supplier, ['external_ref' => 'A', 'category_raw' => 'Free Fire Global']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $this->actingAsAdmin();

        $response = $this->postJson('/api/middleware/supplier-products/categories/link', [
            'category_raw' => 'Free Fire Global',
            'game_id' => $game->id,
        ]);

        $response->assertOk();
        $this->assertSame(1, Game::query()->count()); // no duplicate created
        $this->assertSame($game->id, SupplierProduct::query()->where('external_ref', 'A')->firstOrFail()->game_id);
    }

    public function test_link_category_rejects_both_game_id_and_new_game_given_together(): void
    {
        $supplier = $this->supplier();
        $this->rawProduct($supplier, ['category_raw' => 'Free Fire Global']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $this->actingAsAdmin();

        $response = $this->postJson('/api/middleware/supplier-products/categories/link', [
            'category_raw' => 'Free Fire Global',
            'game_id' => $game->id,
            'new_game' => ['name' => 'Another Game'],
        ]);

        $response->assertUnprocessable();
    }

    public function test_link_category_rejects_when_neither_game_id_nor_new_game_given(): void
    {
        $supplier = $this->supplier();
        $this->rawProduct($supplier, ['category_raw' => 'Free Fire Global']);
        $this->actingAsAdmin();

        $response = $this->postJson('/api/middleware/supplier-products/categories/link', [
            'category_raw' => 'Free Fire Global',
        ]);

        $response->assertUnprocessable();
    }

    /**
     * No `reseller_cost_price`/markup field is sent (founder revision) —
     * every promoted Package gets `config('packages.default_markup_percent')`
     * applied automatically; admin adjusts it afterward in /admin/games.
     */
    public function test_promote_creates_a_package_under_the_given_game(): void
    {
        config(['packages.default_markup_percent' => 15.0]);

        $supplier = $this->supplier();
        $product = $this->rawProduct($supplier); // price_sen 1164
        $game = Game::query()->create(['name' => 'Mobile Legends (Malaysia)', 'slug' => 'mobile-legends-malaysia']);
        $this->actingAsAdmin();

        $response = $this->postJson("/api/middleware/supplier-products/{$product->id}/promote", [
            'game_id' => $game->id,
            'name' => '14 Diamond (13+1 Bonus)',
        ]);

        $response->assertCreated();

        $package = Package::query()->where('supplier_package_ref', 'GV733')->firstOrFail();
        $this->assertSame($game->id, $package->game_id);
        $this->assertSame(1164, $package->cost_price); // snapshotted from the raw price_sen
        $this->assertSame('15.00', (string) $package->markup_percent);
        $this->assertSame(1339, $package->reseller_cost_price); // round(1164 * 1.15)
        $this->assertSame($supplier->id, $package->supplier_id);
        $this->assertTrue($package->is_active);
    }

    public function test_promote_rejects_without_a_game_id(): void
    {
        $supplier = $this->supplier();
        $product = $this->rawProduct($supplier);
        $this->actingAsAdmin();

        $response = $this->postJson("/api/middleware/supplier-products/{$product->id}/promote", [
            'name' => '14 Diamond',
        ]);

        $response->assertUnprocessable();
        $this->assertSame(0, Package::query()->count());
    }

    /**
     * `packages.cost_price` is NOT NULL — a raw item with no price yet
     * (supplier sent none) must be rejected, not silently coerced to 0.
     */
    public function test_promote_rejects_a_product_with_no_price_yet(): void
    {
        $supplier = $this->supplier();
        $product = $this->rawProduct($supplier, ['price_sen' => null]);
        $game = Game::query()->create(['name' => 'Mobile Legends (Malaysia)', 'slug' => 'mobile-legends-malaysia']);
        $this->actingAsAdmin();

        $response = $this->postJson("/api/middleware/supplier-products/{$product->id}/promote", [
            'game_id' => $game->id,
            'name' => '14 Diamond',
        ]);

        $response->assertUnprocessable();
        $this->assertSame(0, Package::query()->count());
    }
}
