<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-094 decisions 1-4, 18-20 (Phase 1 — data model + creation
 * endpoint): the one genuinely new "create a Package from scratch"
 * entry point — PackageController's own doc comment notes every other
 * Package traces back to a real supplier item.
 */
class ComboPackageControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
    }

    private function game(array $overrides = []): Game
    {
        return Game::query()->create(array_merge([
            'name' => 'MLBB Malaysia',
            'slug' => 'mlbb-malaysia',
        ], $overrides));
    }

    private function supplier(array $overrides = []): Supplier
    {
        return Supplier::query()->create(array_merge([
            'name' => 'Gamevion',
            'slug' => 'gamevion',
            'api_config' => [],
            'currency' => 'MYR',
        ], $overrides));
    }

    private function package(Game $game, Supplier $supplier, array $overrides = []): Package
    {
        return Package::query()->create(array_merge([
            'game_id' => $game->id,
            'name' => '4810 Diamonds',
            'denomination' => 4810,
            'cost_price' => 40000,
            'standard_selling_price' => 44000,
            'markup_percent' => 10,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'GV-4810',
        ], $overrides));
    }

    public function test_store_combo_requires_authentication(): void
    {
        $game = $this->game();

        $this->postJson("/api/games/{$game->id}/packages/combo", [])->assertUnauthorized();
    }

    public function test_store_combo_assembles_a_combo_package_from_its_components(): void
    {
        $game = $this->game();
        $supplier = $this->supplier();
        $a = $this->package($game, $supplier, [
            'name' => '4810 Diamonds', 'denomination' => 4810,
            'cost_price' => 40000, 'standard_selling_price' => 44000,
        ]);
        $b = $this->package($game, $supplier, [
            'name' => '2976 Diamonds', 'denomination' => 2976,
            'cost_price' => 25000, 'standard_selling_price' => 27500,
            'supplier_package_ref' => 'GV-2976',
        ]);
        $this->actingAsAdmin();

        $response = $this->postJson("/api/games/{$game->id}/packages/combo", [
            'name' => '7786 Diamonds (Combo)',
            'components' => [
                ['package_id' => $a->id, 'quantity' => 1],
                ['package_id' => $b->id, 'quantity' => 1],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('is_combo', true);
        $response->assertJsonPath('denomination', 4810 + 2976);
        $response->assertJsonPath('cost_price', 40000 + 25000);
        $response->assertJsonPath('standard_selling_price', 44000 + 27500);
        $response->assertJsonPath('supplier_id', null);
        $response->assertJsonPath('supplier_package_ref', null);

        $combo = Package::query()->findOrFail($response->json('id'));
        $this->assertTrue($combo->is_combo);
        $this->assertCount(2, $combo->components);
        $this->assertSame([$a->id, $b->id], $combo->components->pluck('id')->all());
        $this->assertSame(1, $combo->components->firstWhere('id', $a->id)->pivot->quantity);
    }

    public function test_store_combo_supports_a_repeated_component_via_quantity(): void
    {
        $game = $this->game();
        $supplier = $this->supplier();
        $a = $this->package($game, $supplier);
        $this->actingAsAdmin();

        $response = $this->postJson("/api/games/{$game->id}/packages/combo", [
            'name' => '9620 Diamonds (Combo)',
            'components' => [
                ['package_id' => $a->id, 'quantity' => 2],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('denomination', 4810 * 2);
        $response->assertJsonPath('cost_price', 40000 * 2);
    }

    public function test_store_combo_rejects_more_than_three_total_legs(): void
    {
        $game = $this->game();
        $supplier = $this->supplier();
        $a = $this->package($game, $supplier);
        $this->actingAsAdmin();

        $response = $this->postJson("/api/games/{$game->id}/packages/combo", [
            'name' => 'Too Many Legs',
            'components' => [
                ['package_id' => $a->id, 'quantity' => 4],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['components']);
    }

    public function test_store_combo_rejects_cross_supplier_components(): void
    {
        $game = $this->game();
        $supplierA = $this->supplier(['name' => 'Gamevion', 'slug' => 'gamevion']);
        $supplierB = $this->supplier(['name' => 'Digiflazz', 'slug' => 'digiflazz']);
        $a = $this->package($game, $supplierA);
        $b = $this->package($game, $supplierB, [
            'name' => '2976 Diamonds', 'denomination' => 2976, 'supplier_package_ref' => 'DF-2976',
        ]);
        $this->actingAsAdmin();

        $response = $this->postJson("/api/games/{$game->id}/packages/combo", [
            'name' => 'Cross Supplier Combo',
            'components' => [
                ['package_id' => $a->id, 'quantity' => 1],
                ['package_id' => $b->id, 'quantity' => 1],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['components']);
    }

    public function test_store_combo_rejects_a_component_that_is_itself_a_combo(): void
    {
        $game = $this->game();
        $supplier = $this->supplier();
        $a = $this->package($game, $supplier);
        $nestedCombo = $this->package($game, $supplier, [
            'name' => 'Already A Combo', 'denomination' => 9999,
            'supplier_id' => null, 'supplier_package_ref' => null, 'is_combo' => true,
        ]);
        $this->actingAsAdmin();

        $response = $this->postJson("/api/games/{$game->id}/packages/combo", [
            'name' => 'Nested Combo Attempt',
            'components' => [
                ['package_id' => $a->id, 'quantity' => 1],
                ['package_id' => $nestedCombo->id, 'quantity' => 1],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['components.1.package_id']);
    }

    public function test_store_combo_rejects_a_component_from_a_different_game(): void
    {
        $game = $this->game();
        $otherGame = $this->game(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $supplier = $this->supplier();
        $a = $this->package($game, $supplier);
        $foreign = $this->package($otherGame, $supplier, [
            'name' => 'FF Diamonds', 'denomination' => 100, 'supplier_package_ref' => 'GV-FF-100',
        ]);
        $this->actingAsAdmin();

        $response = $this->postJson("/api/games/{$game->id}/packages/combo", [
            'name' => 'Cross Game Combo',
            'components' => [
                ['package_id' => $a->id, 'quantity' => 1],
                ['package_id' => $foreign->id, 'quantity' => 1],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['components.1.package_id']);
    }

    public function test_store_combo_rejects_a_component_with_no_denomination(): void
    {
        $game = $this->game();
        $supplier = $this->supplier();
        $a = $this->package($game, $supplier);
        $bundle = $this->package($game, $supplier, [
            'name' => 'Weekly Pass', 'denomination' => null, 'catalog_code' => 'PASS1',
            'supplier_package_ref' => 'GV-PASS1',
        ]);
        $this->actingAsAdmin();

        $response = $this->postJson("/api/games/{$game->id}/packages/combo", [
            'name' => 'Combo With Bundle',
            'components' => [
                ['package_id' => $a->id, 'quantity' => 1],
                ['package_id' => $bundle->id, 'quantity' => 1],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['components.1.package_id']);
    }

    public function test_store_combo_rejects_duplicate_component_rows(): void
    {
        $game = $this->game();
        $supplier = $this->supplier();
        $a = $this->package($game, $supplier);
        $this->actingAsAdmin();

        $response = $this->postJson("/api/games/{$game->id}/packages/combo", [
            'name' => 'Duplicate Rows',
            'components' => [
                ['package_id' => $a->id, 'quantity' => 1],
                ['package_id' => $a->id, 'quantity' => 1],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['components.0.package_id', 'components.1.package_id']);
    }

    private function combo(Game $game, Package $component, int $quantity = 1): Package
    {
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true,
            'denomination' => 0, 'cost_price' => 0, 'standard_selling_price' => 0, 'markup_percent' => 0,
        ]);
        $combo->components()->attach($component->id, ['quantity' => $quantity, 'sort_order' => 0]);

        return $combo;
    }

    public function test_update_combo_override_sets_a_custom_markup(): void
    {
        $game = $this->game();
        $component = $this->package($game, $this->supplier());
        $combo = $this->combo($game, $component);
        $this->actingAsAdmin();

        $response = $this->patchJson("/api/packages/{$combo->id}/combo-override", [
            'combo_override_markup_percent' => 25,
        ]);

        $response->assertOk();
        $combo->refresh();
        $this->assertSame('25.00', (string) $combo->combo_override_markup_percent);
        $this->assertSame(50000, $combo->standard_selling_price); // round(40000 * 1.25)
    }

    public function test_update_combo_override_sets_a_custom_fixed_price(): void
    {
        $game = $this->game();
        $component = $this->package($game, $this->supplier());
        $combo = $this->combo($game, $component);
        $this->actingAsAdmin();

        $response = $this->patchJson("/api/packages/{$combo->id}/combo-override", [
            'combo_override_price' => 39900,
        ]);

        $response->assertOk();
        $this->assertSame(39900, $combo->refresh()->standard_selling_price);
    }

    public function test_update_combo_override_rejects_both_fields_set_at_once(): void
    {
        $game = $this->game();
        $component = $this->package($game, $this->supplier());
        $combo = $this->combo($game, $component);
        $this->actingAsAdmin();

        $response = $this->patchJson("/api/packages/{$combo->id}/combo-override", [
            'combo_override_markup_percent' => 25,
            'combo_override_price' => 39900,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['combo_override_price']);
    }

    public function test_update_combo_override_rejects_a_non_combo_package(): void
    {
        $game = $this->game();
        $package = $this->package($game, $this->supplier());
        $this->actingAsAdmin();

        $response = $this->patchJson("/api/packages/{$package->id}/combo-override", [
            'combo_override_markup_percent' => 25,
        ]);

        $response->assertUnprocessable();
    }

    public function test_update_combo_override_clearing_both_fields_reverts_to_the_default_sum(): void
    {
        $game = $this->game();
        $component = $this->package($game, $this->supplier());
        $combo = $this->combo($game, $component);
        $combo->update(['combo_override_price' => 1]);
        $this->actingAsAdmin();

        $this->patchJson("/api/packages/{$combo->id}/combo-override", [
            'combo_override_markup_percent' => null,
            'combo_override_price' => null,
        ])->assertOk();

        $combo->refresh();
        $this->assertNull($combo->combo_override_price);
        $this->assertSame(44000, $combo->standard_selling_price); // back to the component's own sum
    }
}
