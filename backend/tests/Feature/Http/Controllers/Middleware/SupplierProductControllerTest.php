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
        $attributes = array_merge([
            'supplier_id' => $supplier->id,
            'external_ref' => 'GV733',
            'name' => '14 Diamond (13+1 Bonus)',
            'category_raw' => 'Mobile legends Global ',
            'price_sen' => 1164,
            'status_raw' => 'active',
            'last_synced_at' => now(),
        ], $overrides);

        // Mirror ProductSyncService's `groupLabel ?? category` fallback
        // so a test that only sets `category_raw` still groups the way
        // Gamevion's real sync does (ADR-067 decision 4).
        $attributes['group_label'] ??= $attributes['category_raw'] ?? '';

        return SupplierProduct::query()->create($attributes);
    }

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/middleware/supplier-products')->assertForbidden();
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
            'standard_selling_price' => 1300,
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

    public function test_index_can_filter_by_supplier_and_group_label(): void
    {
        $gamevion = $this->supplier();
        $digiflazz = Supplier::query()->create([
            'name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR',
        ]);
        $this->rawProduct($gamevion, ['external_ref' => 'A', 'category_raw' => 'Free Fire Global', 'group_label' => 'Free Fire Global']);
        $this->rawProduct($gamevion, ['external_ref' => 'B', 'category_raw' => 'Free Fire (Malaysia)', 'group_label' => 'Free Fire (Malaysia)']);
        // Same group_label string, different supplier — must not leak in.
        $this->rawProduct($digiflazz, ['external_ref' => 'C', 'category_raw' => 'Games', 'group_label' => 'Free Fire Global']);

        $this->actingAsAdmin();

        $response = $this->getJson(
            '/api/middleware/supplier-products?supplier_id='.$gamevion->id.'&group_label='.urlencode('Free Fire Global'),
        );

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
            'standard_selling_price' => 1300,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => $linked->external_ref,
        ]);

        $this->actingAsAdmin();

        $response = $this->getJson('/api/middleware/supplier-products/categories');

        $response->assertOk();
        $byGroup = collect($response->json())->keyBy('group_label');

        $this->assertSame(2, $byGroup['Free Fire Global']['total']);
        $this->assertSame(1, $byGroup['Free Fire Global']['promoted_count']);
        $this->assertSame($game->id, $byGroup['Free Fire Global']['game']['id']);
        $this->assertSame('gamevion', $byGroup['Free Fire Global']['supplier']['slug']);

        $this->assertSame(1, $byGroup['Mobile Legends']['total']);
        $this->assertNull($byGroup['Mobile Legends']['game']);
    }

    /**
     * ADR-067 decision 6: the same group_label from two suppliers is
     * two rows, not one merged blob — the founder links each to a Game
     * separately, and a supplier column tells them apart.
     */
    public function test_categories_keeps_two_suppliers_same_label_group_separate(): void
    {
        $gamevion = $this->supplier();
        $digiflazz = Supplier::query()->create([
            'name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR',
        ]);
        $this->rawProduct($gamevion, ['external_ref' => 'A', 'category_raw' => 'Mobile Legends', 'group_label' => 'Mobile Legends']);
        $this->rawProduct($digiflazz, ['external_ref' => 'B', 'category_raw' => 'Games', 'group_label' => 'Mobile Legends']);
        $this->rawProduct($digiflazz, ['external_ref' => 'C', 'category_raw' => 'Games', 'group_label' => 'Mobile Legends']);

        $this->actingAsAdmin();

        $rows = collect($this->getJson('/api/middleware/supplier-products/categories')->assertOk()->json())
            ->filter(fn ($r) => $r['group_label'] === 'Mobile Legends')
            ->keyBy(fn ($r) => $r['supplier']['slug']);

        $this->assertSame(1, $rows['gamevion']['total']);
        $this->assertSame(2, $rows['digiflazz']['total']);
    }

    /**
     * Founder ask, 2026-07-25: admin wants to double-confirm the
     * `validation_rules` set at link time by seeing it again when
     * viewing an already-linked category (not just trusting it was
     * saved correctly) — so `categories()` must actually return it,
     * not just `game_id`/`name`.
     */
    public function test_categories_includes_the_linked_games_validation_rules(): void
    {
        $supplier = $this->supplier();
        $game = Game::query()->create([
            'name' => 'Mobile Legends', 'slug' => 'mobile-legends',
            'validation_rules' => ['extra_field' => 'zone_id'],
        ]);
        $this->rawProduct($supplier, ['external_ref' => 'A', 'category_raw' => 'Mobile Legends', 'game_id' => $game->id]);

        $this->actingAsAdmin();

        $response = $this->getJson('/api/middleware/supplier-products/categories');

        $response->assertOk();
        $byGroup = collect($response->json())->keyBy('group_label');
        $this->assertSame(['extra_field' => 'zone_id'], $byGroup['Mobile Legends']['game']['validation_rules']);
    }

    public function test_link_category_creates_a_new_game_and_stamps_every_item_in_the_category(): void
    {
        $supplier = $this->supplier();
        $this->rawProduct($supplier, ['external_ref' => 'A', 'category_raw' => 'Free Fire Global']);
        $this->rawProduct($supplier, ['external_ref' => 'B', 'category_raw' => 'Free Fire Global']);
        $this->rawProduct($supplier, ['external_ref' => 'C', 'category_raw' => 'Mobile Legends']);
        $this->actingAsAdmin();

        $response = $this->postJson('/api/middleware/supplier-products/categories/link', [
            'supplier_id' => $supplier->id,
            'group_label' => 'Free Fire Global',
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
            'supplier_id' => $supplier->id,
            'group_label' => 'Free Fire Global',
            'game_id' => $game->id,
        ]);

        $response->assertOk();
        $this->assertSame(1, Game::query()->count()); // no duplicate created
        $this->assertSame($game->id, SupplierProduct::query()->where('external_ref', 'A')->firstOrFail()->game_id);
    }

    /**
     * ADR-005 addendum: Gamevion's order endpoint has no field schema
     * of its own, so we must know per-game whether checkout needs a
     * second field beyond Player ID (UID), and what to call it — set
     * here, at the same "which Game" decision, not a separate trip to
     * /admin/games afterward.
     */
    public function test_link_category_sets_validation_rules_on_a_new_game(): void
    {
        $supplier = $this->supplier();
        $this->rawProduct($supplier, ['external_ref' => 'A', 'category_raw' => 'Mobile Legends']);
        $this->actingAsAdmin();

        $response = $this->postJson('/api/middleware/supplier-products/categories/link', [
            'supplier_id' => $supplier->id,
            'group_label' => 'Mobile Legends',
            'new_game' => ['name' => 'Mobile Legends'],
            'validation_rules' => ['extra_field' => 'zone_id'],
        ]);

        $response->assertOk();
        $game = Game::query()->where('name', 'Mobile Legends')->firstOrFail();
        $this->assertSame(['extra_field' => 'zone_id'], $game->validation_rules);
    }

    public function test_link_category_sets_validation_rules_on_an_existing_game(): void
    {
        $supplier = $this->supplier();
        $this->rawProduct($supplier, ['external_ref' => 'A', 'category_raw' => 'Free Fire Global']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $this->actingAsAdmin();

        $response = $this->postJson('/api/middleware/supplier-products/categories/link', [
            'supplier_id' => $supplier->id,
            'group_label' => 'Free Fire Global',
            'game_id' => $game->id,
            'validation_rules' => ['extra_field' => 'server_id'],
        ]);

        $response->assertOk();
        $this->assertSame(['extra_field' => 'server_id'], $game->refresh()->validation_rules);
    }

    public function test_link_category_rejects_an_unknown_extra_field_value(): void
    {
        $supplier = $this->supplier();
        $this->rawProduct($supplier, ['category_raw' => 'Free Fire Global']);
        $this->actingAsAdmin();

        $response = $this->postJson('/api/middleware/supplier-products/categories/link', [
            'supplier_id' => $supplier->id,
            'group_label' => 'Free Fire Global',
            'new_game' => ['name' => 'Free Fire Global'],
            'validation_rules' => ['extra_field' => 'account_number'],
        ]);

        $response->assertUnprocessable();
    }

    public function test_link_category_rejects_both_game_id_and_new_game_given_together(): void
    {
        $supplier = $this->supplier();
        $this->rawProduct($supplier, ['category_raw' => 'Free Fire Global']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $this->actingAsAdmin();

        $response = $this->postJson('/api/middleware/supplier-products/categories/link', [
            'supplier_id' => $supplier->id,
            'group_label' => 'Free Fire Global',
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
            'supplier_id' => $supplier->id,
            'group_label' => 'Free Fire Global',
        ]);

        $response->assertUnprocessable();
    }

    /**
     * No `standard_selling_price`/markup field is sent (founder revision) —
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
        $this->assertSame(1339, $package->standard_selling_price); // round(1164 * 1.15)
        $this->assertSame($supplier->id, $package->supplier_id);
        $this->assertTrue($package->is_active);
    }

    /**
     * ADR-034 decision 3: denomination can be curated right at promote
     * time (the other half — editing an already-promoted package — is
     * PackageControllerTest::test_update_denomination_sets_the_value).
     */
    public function test_promote_accepts_an_optional_denomination(): void
    {
        config(['packages.default_markup_percent' => 15.0]);

        $supplier = $this->supplier();
        $product = $this->rawProduct($supplier);
        $game = Game::query()->create(['name' => 'Mobile Legends (Malaysia)', 'slug' => 'mobile-legends-malaysia']);
        $this->actingAsAdmin();

        $response = $this->postJson("/api/middleware/supplier-products/{$product->id}/promote", [
            'game_id' => $game->id,
            'name' => '14 Diamond (13+1 Bonus)',
            'denomination' => 14,
        ]);

        $response->assertCreated();
        $package = Package::query()->where('supplier_package_ref', 'GV733')->firstOrFail();
        $this->assertSame(14, $package->denomination);
    }

    public function test_promote_leaves_denomination_null_when_omitted(): void
    {
        config(['packages.default_markup_percent' => 15.0]);

        $supplier = $this->supplier();
        $product = $this->rawProduct($supplier);
        $game = Game::query()->create(['name' => 'Mobile Legends (Malaysia)', 'slug' => 'mobile-legends-malaysia']);
        $this->actingAsAdmin();

        $response = $this->postJson("/api/middleware/supplier-products/{$product->id}/promote", [
            'game_id' => $game->id,
            'name' => '14 Diamond (13+1 Bonus)',
        ]);

        $response->assertCreated();
        $package = Package::query()->where('supplier_package_ref', 'GV733')->firstOrFail();
        $this->assertNull($package->denomination);
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

    /**
     * ADR-025 decision #1: the same floor check the sync path enforces
     * applies here too — a zero price is never legitimate.
     */
    public function test_promote_rejects_a_product_with_a_zero_price(): void
    {
        $supplier = $this->supplier();
        $product = $this->rawProduct($supplier, ['price_sen' => 0]);
        $game = Game::query()->create(['name' => 'Mobile Legends (Malaysia)', 'slug' => 'mobile-legends-malaysia']);
        $this->actingAsAdmin();

        $response = $this->postJson("/api/middleware/supplier-products/{$product->id}/promote", [
            'game_id' => $game->id,
            'name' => '14 Diamond',
        ]);

        $response->assertUnprocessable();
        $this->assertSame(0, Package::query()->count());
    }

    /**
     * Discovered live 2026-07-25: calling this endpoint twice for the
     * same raw item created two Packages selling identical inventory —
     * the frontend only hid the "Add Again" button after promotion
     * (client-side convenience), no backend guard existed. Now backed
     * by a real unique index (supplier_id, supplier_package_ref), not
     * just this friendly check.
     */
    public function test_promote_rejects_an_already_promoted_item(): void
    {
        $supplier = $this->supplier();
        $product = $this->rawProduct($supplier);
        $game = Game::query()->create(['name' => 'Mobile Legends (Malaysia)', 'slug' => 'mobile-legends-malaysia']);
        $this->actingAsAdmin();

        $first = $this->postJson("/api/middleware/supplier-products/{$product->id}/promote", [
            'game_id' => $game->id,
            'name' => '14 Diamond (13+1 Bonus)',
        ]);
        $first->assertCreated();

        $second = $this->postJson("/api/middleware/supplier-products/{$product->id}/promote", [
            'game_id' => $game->id,
            'name' => '14 Diamond (13+1 Bonus) — duplicate attempt',
        ]);

        $second->assertUnprocessable();
        $second->assertJsonValidationErrors('supplier_product');
        $this->assertSame(1, Package::query()->where('supplier_package_ref', 'GV733')->count());
    }
}
