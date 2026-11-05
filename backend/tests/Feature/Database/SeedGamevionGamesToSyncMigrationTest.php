<?php

namespace Tests\Feature\Database;

use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ADR-137 2026-11-05 decision 1: the first games list is written once and never over an edited one. */
class SeedGamevionGamesToSyncMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        (require database_path('migrations/2026_11_09_120000_seed_gamevion_games_to_sync.php'))->up();
    }

    public function test_it_seeds_the_agreed_games_once_and_keeps_the_rest_of_the_config(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'currency' => 'MYR', 'api_config' => ['base_url' => 'https://gamevion.cloud', 'api_key' => 'gvk_x']]);

        $this->runMigration();

        $config = $supplier->refresh()->api_config;
        $this->assertCount(74, $config['games']);
        $this->assertContains('mlbb_sgmy', $config['games']);
        $this->assertNotContains('call_of_duty_mobile_my', $config['games']);
        $this->assertSame('gvk_x', $config['api_key']);
    }

    public function test_it_never_overwrites_a_list_chosen_in_the_picker(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'currency' => 'MYR', 'api_config' => ['base_url' => 'https://gamevion.cloud', 'api_key' => 'gvk_x', 'games' => ['hok']]]);

        $this->runMigration();

        $this->assertSame(['hok'], $supplier->refresh()->api_config['games']);
    }

    public function test_it_does_nothing_without_a_gamevion_supplier(): void
    {
        $before = Supplier::query()->get()->map->api_config->all();

        $this->runMigration();

        $this->assertSame($before, Supplier::query()->get()->map->api_config->all());
    }
}
