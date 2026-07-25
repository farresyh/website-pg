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

    /**
     * ADR-014: packages() is cached per game — PackageController's own
     * updateStatus() must invalidate it (GameController::
     * forgetPackagesCache()), proven end-to-end through both real
     * endpoints rather than by calling the cache helper directly.
     */
    public function test_packages_cache_is_invalidated_when_a_package_status_changes(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 421, 'reseller_cost_price' => 500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        $this->actingAsAdmin();

        $this->getJson("/api/games/{$game->id}/packages")->assertJsonPath('0.is_active', true);

        $this->patchJson("/api/packages/{$package->id}/status", ['is_active' => false])->assertOk();

        $this->getJson("/api/games/{$game->id}/packages")->assertJsonPath('0.is_active', false);
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

    /**
     * ADR-014: the unfiltered index() listing is cached (60s TTL) —
     * proven by mutating the row directly (bypassing the controller's
     * own cache-invalidation) and confirming the stale value still
     * comes back, then invalidating via the real update() endpoint
     * and confirming the fresh value comes back immediately after.
     */
    public function test_index_caches_the_unfiltered_listing_and_invalidates_on_update(): void
    {
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        $this->actingAsAdmin();

        $first = $this->getJson('/api/games');
        $first->assertJsonPath('0.is_active', true);

        // Bypasses GameController::forgetIndexCache() on purpose —
        // simulates "something changed without going through the
        // cached endpoint's own write path".
        $game->update(['is_active' => false]);

        $stillCached = $this->getJson('/api/games');
        $stillCached->assertJsonPath('0.is_active', true);

        $this->putJson("/api/games/{$game->id}", [
            'name' => $game->name, 'slug' => $game->slug, 'is_active' => false,
        ])->assertOk();

        $fresh = $this->getJson('/api/games');
        $fresh->assertJsonPath('0.is_active', false);
    }

    /**
     * A search query must never read the cached, unfiltered listing —
     * proven by caching an empty-search response first, then asserting
     * a real search still filters correctly instead of returning the
     * cached full list.
     */
    public function test_index_does_not_cache_a_filtered_search(): void
    {
        Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        $this->actingAsAdmin();

        $this->getJson('/api/games')->assertJsonCount(2);

        $filtered = $this->getJson('/api/games?search=Free+Fire');
        $filtered->assertJsonCount(1);
        $filtered->assertJsonPath('0.name', 'Free Fire Global');
    }
}
