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
            'reseller_cost_price' => '421',
            'supplier_id' => $this->supplier()->id,
            'supplier_package_ref' => '31478',
        ]);

        $reloaded = Package::query()->findOrFail($package->id);

        $this->assertSame(421, $reloaded->cost_price);
        $this->assertIsInt($reloaded->reseller_cost_price);
    }

    public function test_is_active_defaults_to_true(): void
    {
        $package = Package::query()->create([
            'game_id' => $this->game()->id,
            'name' => '100 Diamonds',
            'cost_price' => 421,
            'reseller_cost_price' => 421,
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
            'reseller_cost_price' => 421,
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
            'reseller_cost_price' => 421,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => '31478',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $supplier->delete();
    }
}
