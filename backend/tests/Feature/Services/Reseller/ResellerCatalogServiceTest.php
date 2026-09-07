<?php

namespace Tests\Feature\Services\Reseller;

use App\Http\Controllers\CatalogController;
use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Reseller\ResellerCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-075's catalog-code addendum (2026-09-04), decision 7: the
 * single seam both the Reseller API (PR-E) and Reseller Bot (PR-F)
 * will call to resolve a public product code back to a Package.
 */
class ResellerCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    private function game(array $overrides = []): Game
    {
        return Game::query()->create(array_merge([
            'name' => 'Mobile Legends Malaysia',
            'slug' => 'mobile-legends-malaysia',
            'reseller_code' => 'MLMY',
            'is_active' => true,
        ], $overrides));
    }

    private function supplier(): Supplier
    {
        return Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
    }

    public function test_resolves_a_real_denomination_code(): void
    {
        $game = $this->game();
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'denomination' => 14,
            'cost_price' => 400, 'standard_selling_price' => 500,
            'supplier_id' => $this->supplier()->id, 'supplier_package_ref' => 'A',
        ]);

        $resolved = app(ResellerCatalogService::class)->resolveByCode('MLMY-14');

        $this->assertTrue($resolved->is($package));
    }

    public function test_resolves_a_catalog_code(): void
    {
        $game = $this->game();
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Weekly Pass', 'catalog_code' => 'P1',
            'cost_price' => 400, 'standard_selling_price' => 500,
            'supplier_id' => $this->supplier()->id, 'supplier_package_ref' => 'A',
        ]);

        $resolved = app(ResellerCatalogService::class)->resolveByCode('MLMY-P1');

        $this->assertTrue($resolved->is($package));
    }

    public function test_lookup_is_case_insensitive(): void
    {
        $game = $this->game();
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Weekly Pass', 'catalog_code' => 'P1',
            'cost_price' => 400, 'standard_selling_price' => 500,
            'supplier_id' => $this->supplier()->id, 'supplier_package_ref' => 'A',
        ]);

        $resolved = app(ResellerCatalogService::class)->resolveByCode('mlmy-p1');

        $this->assertTrue($resolved->is($package));
    }

    public function test_picks_the_cheapest_active_package_for_the_denomination(): void
    {
        $game = $this->game();
        $supplier = $this->supplier();
        $cheap = Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond (cheap)', 'denomination' => 14,
            'cost_price' => 400, 'standard_selling_price' => 500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond (pricier)', 'denomination' => 14,
            'cost_price' => 450, 'standard_selling_price' => 550,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B',
        ]);

        $resolved = app(ResellerCatalogService::class)->resolveByCode('MLMY-14');

        $this->assertTrue($resolved->is($cheap));
    }

    public function test_returns_null_for_an_unknown_reseller_code(): void
    {
        $this->game();

        $this->assertNull(app(ResellerCatalogService::class)->resolveByCode('NOPE-14'));
    }

    public function test_returns_null_when_no_active_package_matches(): void
    {
        $this->game();

        $this->assertNull(app(ResellerCatalogService::class)->resolveByCode('MLMY-14'));
    }

    public function test_returns_null_for_a_malformed_code(): void
    {
        $service = app(ResellerCatalogService::class);

        $this->assertNull($service->resolveByCode('MLMY'));
        $this->assertNull($service->resolveByCode('MLMY-'));
        $this->assertNull($service->resolveByCode(''));
    }

    /** ADR-074 decision 3 / ADR-075 decision 5's shared "price list" source. */
    public function test_list_available_pairs_each_package_with_its_public_code(): void
    {
        $game = $this->game();
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'denomination' => 14,
            'cost_price' => 400, 'standard_selling_price' => 500,
            'supplier_id' => $this->supplier()->id, 'supplier_package_ref' => 'A',
        ]);

        $items = app(ResellerCatalogService::class)->listAvailable();

        $this->assertCount(1, $items);
        $this->assertSame('MLMY-14', $items[0]['code']);
        $this->assertSame('14 Diamond', $items[0]['package']->name);
    }

    public function test_list_available_skips_games_without_a_reseller_code(): void
    {
        $game = Game::query()->create(['name' => 'Free Fire', 'slug' => 'free-fire', 'reseller_code' => null, 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'denomination' => 100,
            'cost_price' => 400, 'standard_selling_price' => 500,
            'supplier_id' => $this->supplier()->id, 'supplier_package_ref' => 'A',
        ]);

        $this->assertCount(0, app(ResellerCatalogService::class)->listAvailable());
    }

    public function test_list_available_skips_uncurated_packages(): void
    {
        $game = $this->game();
        Package::query()->create([
            'game_id' => $game->id, 'name' => 'Uncurated', 'denomination' => null, 'catalog_code' => null,
            'cost_price' => 400, 'standard_selling_price' => 500,
            'supplier_id' => $this->supplier()->id, 'supplier_package_ref' => 'A',
        ]);

        $this->assertCount(0, app(ResellerCatalogService::class)->listAvailable());
    }

    /** ADR-077 PR-5 (decision 10): the shaped listing is cached for 60s. */
    public function test_list_available_is_cached_and_invalidated_at_the_catalog_choke_point(): void
    {
        $game = $this->game();
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'denomination' => 14,
            'cost_price' => 400, 'standard_selling_price' => 500,
            'supplier_id' => $this->supplier()->id, 'supplier_package_ref' => 'A',
        ]);

        $service = app(ResellerCatalogService::class);
        $this->assertCount(1, $service->listAvailable());

        // A raw DB insert bypasses every cache-invalidation hook.
        Package::query()->create([
            'game_id' => $game->id, 'name' => '28 Diamond', 'denomination' => 28,
            'cost_price' => 800, 'standard_selling_price' => 900,
            'supplier_id' => Supplier::query()->first()->id, 'supplier_package_ref' => 'B',
        ]);
        $this->assertCount(1, $service->listAvailable(), 'still the cached listing');

        CatalogController::forgetIndexCache();
        $this->assertCount(2, $service->listAvailable(), 'rebuilt after the choke-point flush');
    }

    /** ADR-077 PR-5: the per-game N+1 is gone — query count no longer scales with game count. */
    public function test_list_available_query_count_is_bounded(): void
    {
        $supplier = $this->supplier();

        foreach (['MLMY', 'FFMY', 'PBMY', 'CODMY'] as $i => $code) {
            $game = $this->game(['name' => "Game {$code}", 'slug' => "game-{$code}", 'reseller_code' => $code]);
            Package::query()->create([
                'game_id' => $game->id, 'name' => "{$code} pack", 'denomination' => 10 + $i,
                'cost_price' => 400, 'standard_selling_price' => 500,
                'supplier_id' => $supplier->id, 'supplier_package_ref' => "R{$i}",
            ]);
        }

        CatalogController::forgetIndexCache();
        DB::enableQueryLog();
        $items = app(ResellerCatalogService::class)->listAvailable();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(4, $items);
        $this->assertLessThanOrEqual(2, $count, "expected 2 queries (games + packages), got {$count}");
    }
}
