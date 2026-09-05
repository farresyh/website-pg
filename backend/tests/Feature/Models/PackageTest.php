<?php

namespace Tests\Feature\Models;

use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageTest extends TestCase
{
    use RefreshDatabase;

    private function game(): Game
    {
        return Game::query()->create([
            'name' => 'Free Fire',
            'slug' => 'free-fire',
        ]);
    }

    private function supplier(): Supplier
    {
        return Supplier::query()->create([
            'name' => 'Gamevion',
            'slug' => 'gamevion',
            'api_config' => [],
        ]);
    }

    public function test_money_fields_are_cast_to_integer_sen(): void
    {
        $package = Package::query()->create([
            'game_id' => $this->game()->id,
            'name' => '100 Diamonds',
            'cost_price' => '421',
            'standard_selling_price' => '421',
            'supplier_id' => $this->supplier()->id,
            'supplier_package_ref' => '31478',
        ]);

        $reloaded = Package::query()->findOrFail($package->id);

        $this->assertSame(421, $reloaded->cost_price);
        $this->assertIsInt($reloaded->standard_selling_price);
    }

    public function test_is_active_defaults_to_true(): void
    {
        $package = Package::query()->create([
            'game_id' => $this->game()->id,
            'name' => '100 Diamonds',
            'cost_price' => 421,
            'standard_selling_price' => 421,
            'supplier_id' => $this->supplier()->id,
            'supplier_package_ref' => '31478',
        ]);

        $reloaded = Package::query()->findOrFail($package->id);

        $this->assertTrue($reloaded->is_active);
    }

    public function test_it_belongs_to_a_game_and_a_supplier(): void
    {
        $game = $this->game();
        $supplier = $this->supplier();

        $package = Package::query()->create([
            'game_id' => $game->id,
            'name' => '100 Diamonds',
            'cost_price' => 421,
            'standard_selling_price' => 421,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => '31478',
        ]);

        $this->assertTrue($package->game->is($game));
        $this->assertTrue($package->supplier->is($supplier));
    }

    public function test_deleting_a_supplier_with_live_packages_is_restricted(): void
    {
        $supplier = $this->supplier();

        Package::query()->create([
            'game_id' => $this->game()->id,
            'name' => '100 Diamonds',
            'cost_price' => 421,
            'standard_selling_price' => 421,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => '31478',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $supplier->delete();
    }

    /**
     * ADR-075's catalog-code addendum (2026-09-04), decision 7 — the
     * seam ResellerCatalogService::resolveByCode() delegates to.
     */
    public function test_cheapest_active_for_picks_the_lowest_cost_price_among_matching_denomination(): void
    {
        $game = $this->game();
        $supplier = $this->supplier();
        $cheap = Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond (A)', 'denomination' => 14,
            'cost_price' => 400, 'standard_selling_price' => 500, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'a',
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond (B)', 'denomination' => 14,
            'cost_price' => 450, 'standard_selling_price' => 500, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'b',
        ]);

        $winner = Package::cheapestActiveFor($game->id, 14, null);

        $this->assertTrue($winner->is($cheap));
    }

    public function test_cheapest_active_for_ignores_inactive_packages(): void
    {
        $game = $this->game();
        $supplier = $this->supplier();
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond (cheap, inactive)', 'denomination' => 14,
            'cost_price' => 400, 'standard_selling_price' => 500, 'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'a', 'is_active' => false,
        ]);
        $active = Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond (active)', 'denomination' => 14,
            'cost_price' => 450, 'standard_selling_price' => 500, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'b',
        ]);

        $winner = Package::cheapestActiveFor($game->id, 14, null);

        $this->assertTrue($winner->is($active));
    }

    public function test_cheapest_active_for_resolves_by_catalog_code(): void
    {
        $game = $this->game();
        $supplier = $this->supplier();
        $cheap = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Weekly Pass (A)', 'catalog_code' => 'P1',
            'cost_price' => 400, 'standard_selling_price' => 500, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'a',
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => 'Weekly Pass (B)', 'catalog_code' => 'P1',
            'cost_price' => 450, 'standard_selling_price' => 500, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'b',
        ]);

        $winner = Package::cheapestActiveFor($game->id, null, 'P1');

        $this->assertTrue($winner->is($cheap));
    }

    public function test_cheapest_active_for_returns_null_when_nothing_matches(): void
    {
        $game = $this->game();

        $this->assertNull(Package::cheapestActiveFor($game->id, 999, null));
        $this->assertNull(Package::cheapestActiveFor($game->id, null, 'NOPE'));
        $this->assertNull(Package::cheapestActiveFor($game->id, null, null));
    }

    /** ADR-074's bulk sibling of cheapestActiveFor(), used by ResellerCatalogService::listAvailable(). */
    public function test_cheapest_active_per_game_dedups_each_equivalence_group_independently(): void
    {
        $game = $this->game();
        $supplier = $this->supplier();
        $cheap14 = Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond (A)', 'denomination' => 14,
            'cost_price' => 400, 'standard_selling_price' => 500, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'a',
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond (B)', 'denomination' => 14,
            'cost_price' => 450, 'standard_selling_price' => 500, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'b',
        ]);
        $cheapPass = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Weekly Pass (A)', 'catalog_code' => 'P1',
            'cost_price' => 300, 'standard_selling_price' => 400, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'c',
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => 'Weekly Pass (B)', 'catalog_code' => 'P1',
            'cost_price' => 350, 'standard_selling_price' => 400, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'd',
        ]);
        $uncurated = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Uncurated bundle', 'denomination' => null, 'catalog_code' => null,
            'cost_price' => 999, 'standard_selling_price' => 1099, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'e',
        ]);

        $winners = Package::cheapestActivePerGame($game->id);

        $this->assertCount(3, $winners);
        $this->assertTrue($winners->contains(fn (Package $p) => $p->is($cheap14)));
        $this->assertTrue($winners->contains(fn (Package $p) => $p->is($cheapPass)));
        $this->assertTrue($winners->contains(fn (Package $p) => $p->is($uncurated)));
    }

    public function test_cheapest_active_per_game_ignores_inactive_packages(): void
    {
        $game = $this->game();
        $supplier = $this->supplier();
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond (cheap, inactive)', 'denomination' => 14,
            'cost_price' => 400, 'standard_selling_price' => 500, 'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'a', 'is_active' => false,
        ]);
        $active = Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond (active)', 'denomination' => 14,
            'cost_price' => 450, 'standard_selling_price' => 500, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'b',
        ]);

        $winners = Package::cheapestActivePerGame($game->id);

        $this->assertCount(1, $winners);
        $this->assertTrue($winners->first()->is($active));
    }
}
