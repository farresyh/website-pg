<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-125 decisions 5/6/33/46: a login game is created with its schema,
 * and no write path can mix it with UID packages, a reseller code or the
 * player validator.
 */
class Adr125LoginGameCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
    }

    /** @return list<array<string, mixed>> */
    private function schema(): array
    {
        return [
            ['key' => 'email', 'label' => 'Roblox email', 'type' => 'email', 'required' => true, 'secret' => false, 'is_identifier' => true],
            ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'secret' => true, 'is_identifier' => false],
            ['key' => 'login_via', 'label' => 'Login via', 'type' => 'select', 'options' => ['Email', 'Google'], 'required' => true, 'secret' => false, 'is_identifier' => false, 'junk' => 'x'],
        ];
    }

    private function loginGame(): Game
    {
        return Game::query()->create([
            'name' => 'Roblox', 'slug' => 'roblox', 'delivery_mode' => 'manual', 'delivery_subtext' => 'Within 30 minutes',
            'validation_rules' => ['credential_fields' => $this->schema()],
        ]);
    }

    private function apiSupplier(): Supplier
    {
        return Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
    }

    private function manualSupplier(): Supplier
    {
        return Supplier::query()->create(['name' => 'Roblox A', 'slug' => 'roblox-a', 'driver' => Supplier::DRIVER_MANUAL, 'api_config' => [], 'currency' => 'MYR']);
    }

    public function test_store_creates_an_inactive_manual_login_game_with_a_normalized_schema(): void
    {
        $response = $this->postJson('/api/games', [
            'name' => 'Roblox', 'delivery_subtext' => 'Within 30 minutes', 'member_pricing_enabled' => false,
            'credential_fields' => $this->schema(),
        ])->assertCreated();

        $game = Game::query()->findOrFail($response->json('id'));
        $this->assertTrue($game->isLoginGame());
        $this->assertFalse($game->is_active);
        $this->assertSame('manual', $game->delivery_mode);
        $this->assertFalse($game->memberPricingApplies());
        $this->assertSame('email', $game->identifierField()['key']);
        $this->assertArrayNotHasKey('junk', $game->credentialFields()[2]);
        $this->assertArrayNotHasKey('options', $game->credentialFields()[0]);
    }

    public function test_the_schema_needs_exactly_one_required_non_secret_identifier_and_a_secret_password(): void
    {
        $bad = [
            'two identifiers' => fn ($s) => [...$s, ['key' => 'user', 'label' => 'User', 'type' => 'text', 'required' => true, 'secret' => false, 'is_identifier' => true]],
            'secret identifier' => function ($s) {
                $s[0]['secret'] = true;

                return $s;
            },
            'optional identifier' => function ($s) {
                $s[0]['required'] = false;

                return $s;
            },
            'select identifier' => function ($s) {
                $s[0]['is_identifier'] = false;
                $s[2]['is_identifier'] = true;

                return $s;
            },
            'plain password' => function ($s) {
                $s[1]['secret'] = false;

                return $s;
            },
            'select without options' => function ($s) {
                unset($s[2]['options']);

                return $s;
            },
            'duplicate key' => function ($s) {
                $s[2]['key'] = 'email';

                return $s;
            },
        ];

        foreach ($bad as $case => $mutate) {
            $this->postJson('/api/games', [
                'name' => 'Roblox '.$case, 'delivery_subtext' => 'x', 'member_pricing_enabled' => false,
                'credential_fields' => $mutate($this->schema()),
            ])->assertStatus(422);
        }

        $this->assertSame(0, Game::query()->count());
    }

    public function test_switching_kind_is_refused_while_the_game_has_packages(): void
    {
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb', 'delivery_mode' => 'manual', 'delivery_subtext' => 'x']);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '86 Diamonds', 'cost_price' => 400, 'standard_selling_price' => 460,
            'supplier_id' => $this->apiSupplier()->id, 'supplier_package_ref' => 'A', 'is_active' => false,
        ]);

        $this->putJson("/api/games/{$game->id}/credential-schema", ['credential_fields' => $this->schema()])
            ->assertStatus(422)->assertJsonValidationErrors('credential_fields');

        $login = $this->loginGame();
        Package::query()->create([
            'game_id' => $login->id, 'name' => '800 Robux', 'cost_price' => 4000, 'standard_selling_price' => 4600,
            'supplier_id' => $this->manualSupplier()->id, 'supplier_package_ref' => 'MANUAL-1',
        ]);

        $this->putJson("/api/games/{$login->id}/credential-schema", ['credential_fields' => []])
            ->assertStatus(422)->assertJsonValidationErrors('credential_fields');

        // Editing a login game's schema without switching kind is fine.
        $this->putJson("/api/games/{$login->id}/credential-schema", ['credential_fields' => array_slice($this->schema(), 0, 2)])
            ->assertOk();
    }

    public function test_turning_the_schema_on_replaces_uid_rules_and_needs_login_game_settings(): void
    {
        $game = Game::query()->create([
            'name' => 'eFootball', 'slug' => 'efootball', 'reseller_code' => 'EFB',
            'validation_rules' => ['extra_field' => 'zone_id', 'zone_options' => ['1'], 'player_id_format' => 'text'],
        ]);

        $this->putJson("/api/games/{$game->id}/credential-schema", ['credential_fields' => $this->schema()])
            ->assertStatus(422)->assertJsonValidationErrors(['reseller_code', 'delivery_mode', 'delivery_subtext']);

        $game->update(['reseller_code' => null, 'delivery_mode' => 'manual', 'delivery_subtext' => 'Within 30 minutes']);

        $this->putJson("/api/games/{$game->id}/credential-schema", ['credential_fields' => $this->schema()])->assertOk();

        $this->assertSame(['credential_fields'], array_keys($game->fresh()->validation_rules));
    }

    public function test_a_login_game_refuses_a_reseller_code_the_validator_and_instant_delivery(): void
    {
        $game = $this->loginGame();
        $base = ['name' => 'Roblox', 'slug' => 'roblox', 'is_active' => true, 'delivery_subtext' => 'Within 30 minutes'];

        $this->putJson("/api/games/{$game->id}", [...$base, 'reseller_code' => 'RBX'])->assertJsonValidationErrors('reseller_code');
        $this->putJson("/api/games/{$game->id}", [...$base, 'delivery_mode' => 'instant'])->assertJsonValidationErrors('delivery_mode');
        $this->putJson("/api/games/{$game->id}", [...$base, 'player_validator_enabled' => true])->assertJsonValidationErrors('player_validator_enabled');
        $this->putJson("/api/games/{$game->id}", [...$base, 'delivery_subtext' => null])->assertJsonValidationErrors('delivery_subtext');

        $this->putJson("/api/games/{$game->id}", [...$base, 'member_pricing_enabled' => false])->assertOk();
        $this->assertFalse($game->fresh()->memberPricingApplies());
    }

    public function test_supplier_paths_refuse_a_login_game_and_a_smuggled_schema(): void
    {
        $game = $this->loginGame();
        $supplier = $this->apiSupplier();
        $product = SupplierProduct::query()->create([
            'supplier_id' => $supplier->id, 'external_ref' => 'GV-1', 'name' => '800 Robux', 'category_raw' => 'Roblox',
            'group_label' => 'Roblox', 'price_sen' => 4000, 'status_raw' => 'active', 'last_synced_at' => now(),
        ]);

        $this->postJson('/api/middleware/supplier-products/categories/link', [
            'supplier_id' => $supplier->id, 'group_label' => 'Roblox', 'game_id' => $game->id,
        ])->assertJsonValidationErrors('game_id');

        $this->postJson('/api/middleware/supplier-products/categories/link', [
            'supplier_id' => $supplier->id, 'group_label' => 'Roblox', 'new_game' => ['name' => 'Roblox 2'],
            'validation_rules' => ['credential_fields' => $this->schema()],
        ])->assertJsonValidationErrors('validation_rules.credential_fields');

        $this->postJson("/api/middleware/supplier-products/{$product->id}/promote", [
            'game_id' => $game->id, 'name' => '800 Robux',
        ])->assertJsonValidationErrors('game_id');
    }

    public function test_a_combo_refuses_a_manual_suppliers_package(): void
    {
        $game = $this->loginGame();
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '800 Robux', 'cost_price' => 4000, 'standard_selling_price' => 4600,
            'supplier_id' => $this->manualSupplier()->id, 'supplier_package_ref' => 'MANUAL-1', 'denomination' => 800,
        ]);

        $this->postJson("/api/games/{$game->id}/packages/combo", [
            'name' => '1600 Robux (Combo)', 'components' => [['package_id' => $package->id, 'quantity' => 2]],
        ])->assertJsonValidationErrors('components.0.package_id');
    }
}
