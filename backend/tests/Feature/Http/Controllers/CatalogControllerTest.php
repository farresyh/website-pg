<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Game;
use App\Models\Package;
use App\Models\Reseller;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Public, no-auth catalog (ADR-011) — the storefront's real data
 * source, replacing storefront/src/lib/placeholder-data.ts (docs/prd.md
 * §14/§15's NEXT SESSION pointer, "public catalog endpoint"). Every
 * assertion here also proves what must NEVER be present: cost_price/
 * reseller_cost_price/markup_percent/supplier_id/supplier_package_ref
 * (GameController::packages()'s own admin-only fields).
 */
class CatalogControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeSupplier(): Supplier
    {
        return Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
    }

    public function test_index_lists_only_active_games(): void
    {
        Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        Game::query()->create(['name' => 'Discontinued Game', 'slug' => 'discontinued', 'is_active' => false]);

        $response = $this->getJson('/api/catalog/games');

        $response->assertOk();
        $this->assertSame(['Free Fire Global'], collect($response->json())->pluck('name')->all());
    }

    public function test_index_never_leaks_internal_fields(): void
    {
        Game::query()->create([
            'name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true,
            'supplier_mappings' => [['supplier_id' => 1, 'product_ref' => 'secret-ref']],
            'validation_rules' => ['extra_field' => 'server_id'],
        ]);

        $response = $this->getJson('/api/catalog/games');

        $response->assertOk();
        $game = $response->json()[0];
        $this->assertArrayNotHasKey('supplier_mappings', $game);
        $this->assertArrayNotHasKey('player_validator_profile_id', $game);
        $this->assertSame('server_id', $game['extra_field']);
    }

    public function test_index_includes_price_from_the_cheapest_active_package(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '50 Diamonds', 'cost_price' => 250, 'reseller_cost_price' => 300,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 480, 'reseller_cost_price' => 550,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B', 'is_active' => true,
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '10 Diamonds (inactive)', 'cost_price' => 50, 'reseller_cost_price' => 60,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'C', 'is_active' => false,
        ]);

        $response = $this->getJson('/api/catalog/games');

        $response->assertOk();
        // Platform Owner reseller markup_pct=0 (ADR-013) — selling_price equals reseller_cost_price in MVP.
        $this->assertSame(300, $response->json()[0]['price_from_sen']);
    }

    public function test_index_price_from_is_null_when_no_active_packages_exist(): void
    {
        Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);

        $response = $this->getJson('/api/catalog/games');

        $response->assertOk();
        $this->assertNull($response->json()[0]['price_from_sen']);
    }

    public function test_show_returns_an_active_game_by_slug_with_seo_fields(): void
    {
        Game::query()->create([
            'name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true,
            'seo_title' => 'Top Up Free Fire Diamonds', 'seo_description' => 'Fast Free Fire top-up.',
        ]);

        $response = $this->getJson('/api/catalog/games/free-fire-global');

        $response->assertOk();
        $response->assertJsonPath('slug', 'free-fire-global');
        $response->assertJsonPath('seo_title', 'Top Up Free Fire Diamonds');
    }

    public function test_show_404s_for_an_inactive_game(): void
    {
        Game::query()->create(['name' => 'Discontinued', 'slug' => 'discontinued', 'is_active' => false]);

        $this->getJson('/api/catalog/games/discontinued')->assertNotFound();
    }

    public function test_show_404s_for_an_unknown_slug(): void
    {
        $this->getJson('/api/catalog/games/does-not-exist')->assertNotFound();
    }

    public function test_packages_lists_only_active_packages_with_a_public_safe_shape(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '50 Diamonds', 'cost_price' => 250, 'reseller_cost_price' => 300,
            'markup_percent' => 12.5, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds (inactive)', 'cost_price' => 480, 'reseller_cost_price' => 550,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B', 'is_active' => false,
        ]);

        $response = $this->getJson('/api/catalog/games/free-fire-global/packages');

        $response->assertOk();
        $packages = $response->json();
        $this->assertCount(1, $packages);
        $this->assertSame('50 Diamonds', $packages[0]['name']);
        $this->assertSame(300, $packages[0]['selling_price_sen']);
        foreach (['cost_price', 'reseller_cost_price', 'markup_percent', 'supplier_id', 'supplier_package_ref'] as $secretField) {
            $this->assertArrayNotHasKey($secretField, $packages[0]);
        }
    }

    public function test_packages_applies_reseller_markup_to_the_selling_price(): void
    {
        Reseller::query()->create(['business_name' => 'Platform Owner', 'markup_pct' => 10, 'status' => 'active']);
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '50 Diamonds', 'cost_price' => 250, 'reseller_cost_price' => 300,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);

        $response = $this->getJson('/api/catalog/games/free-fire-global/packages');

        $response->assertOk();
        // 300 + 10% = 330.
        $this->assertSame(330, $response->json()[0]['selling_price_sen']);
    }

    /**
     * ADR-034: two active packages sharing a (game_id, denomination)
     * equivalence key are the same product sold by two suppliers —
     * the storefront must show only the cheaper one, never both.
     */
    public function test_packages_dedups_by_denomination_keeping_the_cheaper_one(): void
    {
        $gamevion = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $digiflazz = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamonds (Gamevion)', 'denomination' => 14,
            'cost_price' => 500, 'reseller_cost_price' => 600,
            'supplier_id' => $gamevion->id, 'supplier_package_ref' => 'GV14', 'is_active' => true,
        ]);
        $cheaper = Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamonds (Digiflazz)', 'denomination' => 14,
            'cost_price' => 480, 'reseller_cost_price' => 550,
            'supplier_id' => $digiflazz->id, 'supplier_package_ref' => 'DF14', 'is_active' => true,
        ]);

        $response = $this->getJson('/api/catalog/games/mobile-legends/packages');

        $response->assertOk();
        $packages = $response->json();
        $this->assertCount(1, $packages);
        $this->assertSame($cheaper->id, $packages[0]['id']);
        $this->assertSame(550, $packages[0]['selling_price_sen']);
    }

    /**
     * Non-integer-amount products (bundles/passes) stay null forever
     * (ADR-034's own Consequence) — must never be collapsed together
     * just because they share the same null "value".
     */
    public function test_packages_with_null_denomination_are_never_deduped(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => 'Starlight Membership', 'denomination' => null,
            'cost_price' => 500, 'reseller_cost_price' => 600,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-SL', 'is_active' => true,
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => 'Weekly Pass', 'denomination' => null,
            'cost_price' => 500, 'reseller_cost_price' => 600,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-WP', 'is_active' => true,
        ]);

        $response = $this->getJson('/api/catalog/games/mobile-legends/packages');

        $response->assertOk();
        $this->assertCount(2, $response->json());
    }

    /**
     * Deterministic tie-break (lower package id) when two duplicate
     * packages price identically — not specified by the ADR itself,
     * but the pick must be stable across requests, not arbitrary.
     */
    public function test_packages_dedup_tie_breaks_on_lower_package_id(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends', 'is_active' => true]);
        $first = Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamonds (A)', 'denomination' => 14,
            'cost_price' => 500, 'reseller_cost_price' => 550,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A14', 'is_active' => true,
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamonds (B)', 'denomination' => 14,
            'cost_price' => 500, 'reseller_cost_price' => 550,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B14', 'is_active' => true,
        ]);

        $response = $this->getJson('/api/catalog/games/mobile-legends/packages');

        $response->assertOk();
        $packages = $response->json();
        $this->assertCount(1, $packages);
        $this->assertSame($first->id, $packages[0]['id']);
    }

    /**
     * ADR-034 follow-up, founder feedback 2026-08-25: same reasoning
     * as GameController::packages()'s own admin-side ordering change —
     * smallest denomination first reads as cheapest-first, sorts
     * numerically instead of alphabetically-by-name. Packages without
     * a curated denomination sort last, by name.
     */
    public function test_packages_orders_by_denomination_ascending_with_nulls_last(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends', 'is_active' => true]);

        Package::query()->create([
            'game_id' => $game->id, 'name' => '10209 Diamonds', 'denomination' => 10209,
            'cost_price' => 61805, 'reseller_cost_price' => 71076,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '13 + 1 Diamonds', 'denomination' => 14,
            'cost_price' => 94, 'reseller_cost_price' => 103,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B', 'is_active' => true,
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '1252 + 194 Diamonds', 'denomination' => null,
            'cost_price' => 9345, 'reseller_cost_price' => 10747,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'C', 'is_active' => true,
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '1192 Diamonds', 'denomination' => 1192,
            'cost_price' => 7281, 'reseller_cost_price' => 8373,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'D', 'is_active' => true,
        ]);

        $response = $this->getJson('/api/catalog/games/mobile-legends/packages');

        $response->assertOk();
        $this->assertSame(
            ['13 + 1 Diamonds', '1192 Diamonds', '10209 Diamonds', '1252 + 194 Diamonds'],
            collect($response->json())->pluck('name')->all(),
        );
    }

    public function test_packages_404s_for_an_inactive_game(): void
    {
        Game::query()->create(['name' => 'Discontinued', 'slug' => 'discontinued', 'is_active' => false]);

        $this->getJson('/api/catalog/games/discontinued/packages')->assertNotFound();
    }

    /**
     * ADR-014 discipline extended to the public catalog: reuses the
     * exact same GameController::forgetIndexCache()/forgetPackagesCache()
     * choke points every admin write already calls through — proven
     * end-to-end via the real admin endpoints, not by calling a cache
     * helper directly.
     */
    public function test_public_index_cache_is_invalidated_when_an_admin_updates_a_game(): void
    {
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);

        $this->getJson('/api/catalog/games')->assertJsonPath('0.name', 'Free Fire Global');

        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\AdminUser::factory()->create(['role' => 'admin']));
        $this->putJson("/api/games/{$game->id}", [
            'name' => $game->name, 'slug' => $game->slug, 'is_active' => false,
        ])->assertOk();

        $this->getJson('/api/catalog/games')->assertJsonCount(0);
    }

    public function test_public_packages_cache_is_invalidated_when_an_admin_changes_a_packages_status(): void
    {
        $supplier = $this->makeSupplier();
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '50 Diamonds', 'cost_price' => 250, 'reseller_cost_price' => 300,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);

        $this->getJson('/api/catalog/games/free-fire-global/packages')->assertJsonCount(1);

        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\AdminUser::factory()->create(['role' => 'admin']));
        $this->patchJson("/api/packages/{$package->id}/status", ['is_active' => false])->assertOk();

        $this->getJson('/api/catalog/games/free-fire-global/packages')->assertJsonCount(0);
    }

    /**
     * Regression test for a real bug found live, 2026-07-26 (same bug
     * class as HeroSlideControllerTest's own regression test): this
     * app's default `database` cache store corrupts any raw object
     * nested inside an otherwise-plain cached array on the next read.
     * `created_at` was left as a raw Carbon instance here — confirmed
     * live it broke on a warm-cache read (`{"__PHP_Incomplete_Class_
     * Name":"Illuminate\\Support\\Carbon", ...}` instead of a date
     * string). Fixed via `?->toISOString()`. PHPUnit's `array` cache
     * driver never serializes, so this forces the real `database`
     * store to prove the cached value is fully scalar.
     */
    public function test_index_survives_a_real_database_cache_round_trip(): void
    {
        config(['cache.default' => 'database']);
        Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global', 'is_active' => true]);

        $first = $this->getJson('/api/catalog/games');
        $first->assertOk();
        $this->assertSame('Free Fire Global', $first->json()[0]['name']);
        $this->assertIsString($first->json()[0]['created_at']);

        $cached = Cache::store('database')->get('catalog.public.games.index');
        $this->assertIsString($cached[0]['created_at']);

        $second = $this->getJson('/api/catalog/games');
        $second->assertOk();
        $this->assertSame('Free Fire Global', $second->json()[0]['name']);
        $this->assertIsString($second->json()[0]['created_at']);
    }
}
