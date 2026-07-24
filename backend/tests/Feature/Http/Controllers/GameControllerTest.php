<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/games')->assertUnauthorized();
    }

    public function test_index_lists_games_ordered_by_name(): void
    {
        Game::query()->create(['name' => 'Zenless Zone Zero', 'slug' => 'zzz']);
        Game::query()->create(['name' => 'Age of Empires Mobile', 'slug' => 'aoe-mobile']);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/games');

        $response->assertOk();
        $this->assertSame(
            ['Age of Empires Mobile', 'Zenless Zone Zero'],
            collect($response->json())->pluck('name')->all(),
        );
    }

    public function test_index_includes_package_count(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 421, 'reseller_cost_price' => 500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/games');

        $response->assertOk();
        $this->assertSame(1, $response->json('0.packages_count'));
    }

    public function test_index_can_filter_by_status(): void
    {
        Game::query()->create(['name' => 'Active Game', 'slug' => 'active-game', 'is_active' => true]);
        Game::query()->create(['name' => 'Inactive Game', 'slug' => 'inactive-game', 'is_active' => false]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/games?status=inactive');

        $response->assertOk();
        $this->assertSame(['Inactive Game'], collect($response->json())->pluck('name')->all());
    }

    public function test_packages_lists_only_this_games_packages(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $otherGame = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);

        Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 421, 'reseller_cost_price' => 500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ]);
        Package::query()->create([
            'game_id' => $otherGame->id, 'name' => '14 Diamond', 'cost_price' => 100, 'reseller_cost_price' => 150,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B',
        ]);

        $this->actingAsAdmin();

        $response = $this->getJson("/api/games/{$game->id}/packages");

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame('100 Diamonds', $response->json('0.name'));
    }

    /**
     * `supplier_active` (founder revision, 2026-07-25): a read-only
     * indicator distinct from `is_active` (our own control) — has the
     * supplier turned this item off on their own side, per the last
     * Stage 1 sync? Display-only for now (docs/prd.md §14).
     */
    public function test_packages_flags_items_the_supplier_has_turned_off(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);

        Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 421, 'reseller_cost_price' => 500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '210 Diamonds', 'cost_price' => 840, 'reseller_cost_price' => 966,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B',
        ]);

        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id, 'external_ref' => 'A', 'name' => '100 Diamonds',
            'price_sen' => 421, 'status_raw' => 'active', 'last_synced_at' => now(),
        ]);
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id, 'external_ref' => 'B', 'name' => '210 Diamonds',
            'price_sen' => 840, 'status_raw' => 'inactive', 'last_synced_at' => now(),
        ]);

        $this->actingAsAdmin();

        $response = $this->getJson("/api/games/{$game->id}/packages");

        $response->assertOk();
        $byRef = collect($response->json())->keyBy('supplier_package_ref');
        $this->assertTrue($byRef['A']['supplier_active']);
        $this->assertFalse($byRef['B']['supplier_active']);
    }

    public function test_update_edits_the_games_own_fields(): void
    {
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        $this->actingAsAdmin();

        $response = $this->putJson("/api/games/{$game->id}", [
            'name' => 'Free Fire (Global)',
            'slug' => 'free-fire-global',
            'category' => 'Battle Royale',
            'image_url' => 'https://example.com/ff.png',
            'banner_url' => null,
            'is_active' => false,
        ]);

        $response->assertOk();
        $game->refresh();
        $this->assertSame('Free Fire (Global)', $game->name);
        $this->assertSame('Battle Royale', $game->category);
        $this->assertFalse($game->is_active);
    }

    public function test_update_rejects_a_slug_already_used_by_another_game(): void
    {
        Game::query()->create(['name' => 'Existing', 'slug' => 'taken-slug']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $this->actingAsAdmin();

        $response = $this->putJson("/api/games/{$game->id}", [
            'name' => 'Free Fire Global', 'slug' => 'taken-slug', 'is_active' => true,
        ]);

        $response->assertUnprocessable();
    }

    /**
     * `packages.game_id` is `cascadeOnDelete()` — deleting a Game must
     * take its Packages with it, not orphan them.
     */
    public function test_destroy_cascades_to_the_games_packages(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 421, 'reseller_cost_price' => 500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ]);
        $this->actingAsAdmin();

        $response = $this->deleteJson("/api/games/{$game->id}");

        $response->assertNoContent();
        $this->assertSame(0, Game::query()->count());
        $this->assertSame(0, Package::query()->count());
    }
}
