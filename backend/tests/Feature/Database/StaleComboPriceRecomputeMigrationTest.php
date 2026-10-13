<?php

namespace Tests\Feature\Database;

use App\Models\Game;
use App\Models\Package;
use App\Models\PriceChangeLog;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Combos left at a stale stored price by the pre-fix reactivation path
 * (found on prod 2026-10-13) are repriced once on deploy; a current one,
 * and a second run, change nothing.
 */
class StaleComboPriceRecomputeMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(): void
    {
        (require database_path('migrations/2026_10_13_100000_recompute_stale_combo_prices.php'))->up();
    }

    private function combo(Package $component, int $storedCost, int $storedSell): Package
    {
        $combo = Package::query()->create([
            'game_id' => $component->game_id, 'name' => 'Combo', 'is_combo' => true, 'denomination' => 172,
            'cost_price' => $storedCost, 'standard_selling_price' => $storedSell, 'markup_percent' => 10,
        ]);
        $combo->components()->attach($component->id, ['quantity' => 2, 'sort_order' => 0]);

        return $combo;
    }

    public function test_reprices_only_stale_combos_and_is_idempotent(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
        $game = Game::query()->create(['name' => 'MLBB Malaysia', 'slug' => 'mlbb-malaysia']);
        $component = Package::query()->create([
            'game_id' => $game->id, 'name' => '86 Diamonds', 'denomination' => 86, 'cost_price' => 1000,
            'standard_selling_price' => 1100, 'markup_percent' => 10, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'ML86',
        ]);
        $stale = $this->combo($component, 2050, 2250);
        $current = $this->combo($component, 2000, 2200);

        $this->migrate();
        $this->migrate();

        $this->assertSame(2000, $stale->refresh()->cost_price);
        $this->assertSame(2200, $stale->standard_selling_price);
        $this->assertSame(1, PriceChangeLog::query()->where('package_id', $stale->id)->count());
        $this->assertSame(0, PriceChangeLog::query()->where('package_id', $current->id)->count());
    }
}
