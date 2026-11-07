<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\SupplierGroupLink;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ADR-067 2026-11-07 addendum: the game link belongs to the group, not to each item. */
class SupplierGroupLinkTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = 'Valorant (Malaysia)';

    private Supplier $supplier;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $this->game = Game::query()->create(['name' => 'Valorant Malaysia', 'slug' => 'valorant-malaysia']);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
    }

    private function item(string $ref, string $group = self::GROUP): SupplierProduct
    {
        return SupplierProduct::query()->create([
            'supplier_id' => $this->supplier->id, 'external_ref' => $ref, 'name' => $ref, 'group_label' => $group,
            'price_sen' => 3435, 'status_raw' => 'active', 'last_synced_at' => now(),
        ]);
    }

    private function package(string $ref, ?Game $game = null): Package
    {
        return Package::query()->create([
            'game_id' => ($game ?? $this->game)->id, 'name' => '1000 VP', 'cost_price' => 3428, 'standard_selling_price' => 3771,
            'is_active' => false, 'deactivated_reason' => 'supplier_issue', 'deactivated_at' => now(),
            'supplier_id' => $this->supplier->id, 'supplier_package_ref' => $ref,
        ]);
    }

    /** @param array<string, mixed> $choice */
    private function link(array $choice): TestResponse
    {
        return $this->postJson('/api/middleware/supplier-products/categories/link', [
            'supplier_id' => $this->supplier->id, 'group_label' => self::GROUP, ...$choice,
        ]);
    }

    private function linkedGameId(): ?int
    {
        return collect($this->getJson('/api/middleware/supplier-products/categories')->json())
            ->firstWhere('group_label', self::GROUP)['game_id'];
    }

    public function test_an_item_synced_after_the_link_can_replace_a_package(): void
    {
        $this->item('26042');
        $package = $this->package('26042');
        $this->link(['game_id' => $this->game->id])->assertOk();

        $new = $this->item('valorant_my:1000');

        $this->postJson("/api/middleware/supplier-products/{$new->id}/repoint", ['package_id' => $package->id])->assertOk();
        $this->assertSame('valorant_my:1000', $package->refresh()->supplier_package_ref);
    }

    public function test_an_item_that_vanishes_and_returns_keeps_the_link(): void
    {
        $item = $this->item('valorant_my:475');
        $this->link(['game_id' => $this->game->id])->assertOk();

        $item->delete();
        $returned = $this->item('valorant_my:475');

        $this->assertSame($this->game->id, $returned->groupLink()?->game_id);
    }

    public function test_a_group_has_one_link_however_many_times_it_is_saved(): void
    {
        $this->item('valorant_my:475');

        $this->link(['game_id' => $this->game->id])->assertOk();
        $this->link(['game_id' => $this->game->id])->assertOk();

        $this->assertSame(1, SupplierGroupLink::query()->count());
    }

    public function test_the_same_label_at_another_supplier_stays_unlinked(): void
    {
        $this->item('valorant_my:475');
        $other = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
        $item = SupplierProduct::query()->create([
            'supplier_id' => $other->id, 'external_ref' => 'VAL_475', 'name' => '475', 'group_label' => self::GROUP,
            'price_sen' => 1669, 'status_raw' => 'active', 'last_synced_at' => now(),
        ]);

        $this->link(['game_id' => $this->game->id])->assertOk();

        $this->assertNull($item->groupLink());
    }

    public function test_a_group_with_no_package_can_change_game(): void
    {
        $this->item('valorant_my:475');
        $this->link(['game_id' => $this->game->id])->assertOk();
        $right = Game::query()->create(['name' => 'Valorant Indonesia', 'slug' => 'valorant-indonesia']);

        $this->link(['game_id' => $right->id])->assertOk();

        $this->assertSame($right->id, $this->linkedGameId());
    }

    public function test_a_group_with_a_package_cannot_leave_that_packages_game(): void
    {
        $this->item('26042');
        $this->package('26042');
        $this->link(['game_id' => $this->game->id])->assertOk();
        $other = Game::query()->create(['name' => 'Valorant Indonesia', 'slug' => 'valorant-indonesia', 'validation_rules' => ['extra_field' => 'zone_id']]);

        $this->link(['game_id' => $other->id, 'validation_rules' => ['extra_field' => null]])->assertJsonValidationErrors('game_id');
        $this->link(['new_game' => ['name' => 'Valorant Elsewhere']])->assertJsonValidationErrors('game_id');

        $this->assertSame($this->game->id, $this->linkedGameId());
        $this->assertSame('zone_id', $other->refresh()->validation_rules['extra_field']);
        $this->assertFalse(Game::query()->where('name', 'Valorant Elsewhere')->exists());
    }

    public function test_a_first_link_cannot_go_against_a_package_in_the_group(): void
    {
        $this->item('26042');
        $this->package('26042');
        $other = Game::query()->create(['name' => 'Valorant Indonesia', 'slug' => 'valorant-indonesia']);

        $this->link(['game_id' => $other->id])->assertJsonValidationErrors('game_id');
        $this->link(['game_id' => $this->game->id])->assertOk();
    }

    /** Checkout Input saves the group's own game: package 516's group must keep working (decision 15). */
    public function test_saving_the_same_game_passes_with_a_package_on_another_game(): void
    {
        $indonesia = Game::query()->create(['name' => 'Mobile Legends Indonesia', 'slug' => 'mobile-legends-indonesia']);
        $this->item('MLBB_ALL_14_PG1');
        $this->package('MLBB_ALL_14_PG1');
        SupplierGroupLink::query()->create(['supplier_id' => $this->supplier->id, 'group_label' => self::GROUP, 'game_id' => $indonesia->id]);

        $this->link(['game_id' => $indonesia->id, 'validation_rules' => ['extra_field' => 'zone_id']])->assertOk();
    }

    public function test_deleting_a_game_unlinks_its_groups(): void
    {
        $this->item('valorant_my:475');
        $this->link(['game_id' => $this->game->id])->assertOk();

        $this->deleteJson("/api/games/{$this->game->id}")->assertNoContent();

        $this->assertNull($this->linkedGameId());
    }
}
