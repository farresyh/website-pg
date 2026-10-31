<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Package;
use App\Models\ResellerTier;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-091 2026-11-01 addendum: `games.family` / `games.region_label` are
 * display-only grouping for /price-list, set in the admin game modal, and
 * the public endpoint answers grouped (decisions 3 to 7).
 */
class Adr091GameFamilyTest extends TestCase
{
    use RefreshDatabase;

    private function game(string $name, string $code, array $overrides = []): Game
    {
        return Game::query()->create(array_merge([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'reseller_code' => $code,
            'is_active' => true,
            'delivery_mode' => 'instant',
        ], $overrides));
    }

    private function price(Game $game, string $package = '86 Diamonds'): void
    {
        $supplier = Supplier::query()->firstOrCreate(
            ['slug' => 'gamevion'],
            ['name' => 'Gamevion', 'api_config' => [], 'currency' => 'MYR'],
        );
        Package::query()->create([
            'game_id' => $game->id, 'name' => $package, 'denomination' => 86, 'cost_price' => 1000,
            'standard_selling_price' => 1200, 'supplier_id' => $supplier->id,
            'supplier_package_ref' => $game->slug.$package, 'is_active' => true,
        ]);
    }

    private function tier(): void
    {
        ResellerTier::query()->create([
            'name' => 'Tier SS', 'markup_percent' => 3, 'is_active' => true, 'show_on_price_list' => true, 'sort_order' => 0,
        ]);
    }

    /** @return array<string, mixed> */
    private function editPayload(Game $game, array $over = []): array
    {
        return array_merge([
            'name' => $game->name, 'slug' => $game->slug, 'is_active' => true, 'delivery_mode' => 'instant',
        ], $over);
    }

    // ---- the public endpoint ----

    public function test_groups_games_of_one_family_case_insensitively_in_sort_order(): void
    {
        $this->primaryAffiliate();
        $this->tier();
        $my = $this->game('Mobile Legends Malaysia', 'MLMY', ['sort_order' => 0, 'family' => 'Mobile Legends', 'region_label' => 'Malaysia', 'image_url' => 'https://cdn.test/ml.webp']);
        $id = $this->game('Mobile legends Indonesia', 'MLID', ['sort_order' => 1, 'family' => 'mobile legends', 'region_label' => 'Indonesia', 'image_url' => 'https://cdn.test/other.webp']);
        $this->price($my);
        $this->price($id);

        $groups = $this->getJson('/api/catalog/reseller-price-list')->assertOk()->json('groups');

        $this->assertCount(1, $groups);
        $this->assertSame('Mobile Legends', $groups[0]['family']);
        $this->assertSame('https://cdn.test/ml.webp', $groups[0]['image_url']);
        $this->assertSame(['Malaysia', 'Indonesia'], array_column($groups[0]['games'], 'region'));
        $this->assertSame('Mobile Legends Malaysia', $groups[0]['games'][0]['name']);
    }

    public function test_a_game_with_no_family_is_its_own_group_named_after_the_game(): void
    {
        $this->primaryAffiliate();
        $this->tier();
        $this->price($this->game('Pokemon Unite', 'PU'));

        $groups = $this->getJson('/api/catalog/reseller-price-list')->assertOk()->json('groups');

        $this->assertSame('Pokemon Unite', $groups[0]['family']);
        $this->assertSame('Pokemon Unite', $groups[0]['games'][0]['region']);
    }

    public function test_a_family_without_a_region_label_falls_back_to_the_game_name(): void
    {
        $this->primaryAffiliate();
        $this->tier();
        $this->price($this->game('Genshin Impact Malaysia', 'GIM', ['family' => 'Genshin Impact']));

        $groups = $this->getJson('/api/catalog/reseller-price-list')->assertOk()->json('groups');

        $this->assertSame('Genshin Impact', $groups[0]['family']);
        $this->assertSame('Genshin Impact Malaysia', $groups[0]['games'][0]['region']);
    }

    public function test_families_are_ordered_by_their_first_members_sort_order(): void
    {
        $this->primaryAffiliate();
        $this->tier();
        $this->price($this->game('Free Fire Malaysia', 'FFMY', ['sort_order' => 5, 'family' => 'Free Fire', 'region_label' => 'Malaysia']));
        $this->price($this->game('PUBG Mobile Global', 'PUBGG', ['sort_order' => 3]));
        $this->price($this->game('Free Fire Indonesia', 'FFID', ['sort_order' => 1, 'family' => 'Free Fire', 'region_label' => 'Indonesia']));

        $groups = $this->getJson('/api/catalog/reseller-price-list')->assertOk()->json('groups');

        $this->assertSame(['Free Fire', 'PUBG Mobile Global'], array_column($groups, 'family'));
        $this->assertSame(['Indonesia', 'Malaysia'], array_column($groups[0]['games'], 'region'));
    }

    public function test_exposes_only_display_fields_and_the_apply_link(): void
    {
        $this->primaryAffiliate();
        $this->tier();
        config(['services.onboarding.url' => 'https://onboarding.example.test/']);
        $this->price($this->game('Pokemon Unite', 'PU'));

        $body = $this->getJson('/api/catalog/reseller-price-list')->assertOk()->json();

        $this->assertSame(['tiers', 'groups', 'apply_url'], array_keys($body));
        $this->assertSame('https://onboarding.example.test/apply', $body['apply_url']);
        $group = $body['groups'][0];
        $this->assertSame(['family', 'image_url', 'games'], array_keys($group));
        $this->assertSame(['name', 'region', 'packages'], array_keys($group['games'][0]));
        $this->assertSame(['name', 'prices'], array_keys($group['games'][0]['packages'][0]));
        $this->assertSame(['tier_id', 'price_sen'], array_keys($group['games'][0]['packages'][0]['prices'][0]));
    }

    public function test_summary_answers_with_the_tiers_alone(): void
    {
        $this->primaryAffiliate();
        $this->tier();
        $this->price($this->game('Pokemon Unite', 'PU'));

        $body = $this->getJson('/api/catalog/reseller-price-list?summary=1')->assertOk()->json();

        $this->assertSame(['tiers'], array_keys($body));
        $this->assertSame(['Tier SS'], array_column($body['tiers'], 'name'));
    }

    public function test_summary_is_empty_when_no_tier_is_on_the_list(): void
    {
        $this->primaryAffiliate();
        $this->price($this->game('Pokemon Unite', 'PU'));

        $this->assertSame(['tiers' => []], $this->getJson('/api/catalog/reseller-price-list?summary=1')->assertOk()->json());
    }

    // ---- the admin edit ----

    public function test_admin_saves_a_family_and_region_trimmed(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
        $game = $this->game('Mobile Legends Malaysia', 'MLMY');

        $this->putJson("/api/games/{$game->id}", $this->editPayload($game, ['family' => '  Mobile   Legends ', 'region_label' => ' Malaysia ']))
            ->assertOk();

        $game->refresh();
        $this->assertSame('Mobile Legends', $game->family);
        $this->assertSame('Malaysia', $game->region_label);
    }

    public function test_a_blank_family_is_stored_as_null(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
        $game = $this->game('Mobile Legends Malaysia', 'MLMY', ['family' => 'Mobile Legends', 'region_label' => 'Malaysia']);

        $this->putJson("/api/games/{$game->id}", $this->editPayload($game, ['family' => '  ', 'region_label' => '']))->assertOk();

        $game->refresh();
        $this->assertNull($game->family);
        $this->assertNull($game->region_label);
    }

    public function test_a_region_without_a_family_is_refused(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
        $game = $this->game('Mobile Legends Malaysia', 'MLMY');

        $this->putJson("/api/games/{$game->id}", $this->editPayload($game, ['region_label' => 'Malaysia']))
            ->assertUnprocessable()->assertJsonValidationErrors('region_label');
    }

    public function test_two_games_cannot_share_a_family_and_region_ignoring_case(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
        $this->game('Mobile Legends Malaysia', 'MLMY', ['family' => 'Mobile Legends', 'region_label' => 'Malaysia']);
        $other = $this->game('Mobile Legends Indonesia', 'MLID');

        $this->putJson("/api/games/{$other->id}", $this->editPayload($other, ['family' => 'mobile legends', 'region_label' => 'MALAYSIA']))
            ->assertUnprocessable()->assertJsonValidationErrors('region_label');
    }

    public function test_a_game_can_keep_its_own_family_and_region_on_save(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
        $game = $this->game('Mobile Legends Malaysia', 'MLMY', ['family' => 'Mobile Legends', 'region_label' => 'Malaysia']);

        $this->putJson("/api/games/{$game->id}", $this->editPayload($game, ['family' => 'Mobile Legends', 'region_label' => 'Malaysia']))->assertOk();
    }

    public function test_a_family_reuses_the_spelling_an_existing_game_already_has(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
        $this->game('Mobile Legends Malaysia', 'MLMY', ['family' => 'Mobile Legends', 'region_label' => 'Malaysia']);
        $other = $this->game('Mobile legends Global', 'MLGB');

        $this->putJson("/api/games/{$other->id}", $this->editPayload($other, ['family' => 'mobile legends', 'region_label' => 'Global']))->assertOk();

        $this->assertSame('Mobile Legends', $other->refresh()->family);
    }

    public function test_an_edit_without_the_fields_leaves_them_alone(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
        $game = $this->game('Mobile Legends Malaysia', 'MLMY', ['family' => 'Mobile Legends', 'region_label' => 'Malaysia']);

        $this->putJson("/api/games/{$game->id}", $this->editPayload($game))->assertOk();

        $this->assertSame('Mobile Legends', $game->refresh()->family);
    }
}
