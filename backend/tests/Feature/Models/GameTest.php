<?php

namespace Tests\Feature\Models;

use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_mappings_round_trips_as_an_array(): void
    {
        $game = Game::query()->create([
            'name' => 'Mobile Legends: Bang Bang (Malaysia)',
            'slug' => 'mobile-legends-bang-bang-malaysia',
            'supplier_mappings' => [
                [
                    'supplier_id' => 1,
                    'product_ref' => 'GV733',
                    'supports_validation' => false,
                    'supports_server_list' => false,
                ],
            ],
        ]);

        $reloaded = Game::query()->findOrFail($game->id);

        $this->assertSame('GV733', $reloaded->supplier_mappings[0]['product_ref']);
    }

    public function test_is_active_defaults_to_true(): void
    {
        $game = Game::query()->create([
            'name' => 'Free Fire',
            'slug' => 'free-fire',
        ]);

        // The DB column default isn't reflected on the in-memory
        // instance create() returns — only a real row read shows it.
        $reloaded = Game::query()->findOrFail($game->id);

        $this->assertTrue($reloaded->is_active);
    }

    public function test_it_has_many_packages(): void
    {
        $game = Game::query()->create([
            'name' => 'Free Fire',
            'slug' => 'free-fire',
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Gamevion',
            'slug' => 'gamevion',
            'api_config' => [],
        ]);

        Package::query()->create([
            'game_id' => $game->id,
            'name' => '100 Diamonds',
            'cost_price' => 421,
            'standard_selling_price' => 421,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => '31478',
        ]);

        $this->assertCount(1, $game->packages);
    }

    public function test_deleting_a_game_cascades_to_its_packages(): void
    {
        $game = Game::query()->create([
            'name' => 'Free Fire',
            'slug' => 'free-fire',
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Gamevion',
            'slug' => 'gamevion',
            'api_config' => [],
        ]);

        $package = Package::query()->create([
            'game_id' => $game->id,
            'name' => '100 Diamonds',
            'cost_price' => 421,
            'standard_selling_price' => 421,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => '31478',
        ]);

        $game->delete();

        $this->assertNull(Package::query()->find($package->id));
    }
}
