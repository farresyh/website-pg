<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Models\Game;
use App\Models\Package;
use App\Models\ResellerTier;
use App\Models\Supplier;
use App\Services\Affiliate\AffiliateDomainStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-091: the public Reseller Price List page's backend — a sales/
 * acquisition surface, shown only on is_owned brands, content platform
 * -wide (reseller_tiers isn't an affiliate concept).
 */
class PublicResellerPriceListControllerTest extends TestCase
{
    use RefreshDatabase;

    private function game(array $overrides = []): Game
    {
        return Game::query()->create(array_merge([
            'name' => 'Mobile Legends',
            'slug' => 'mobile-legends',
            'reseller_code' => 'MLMY',
            'is_active' => true,
        ], $overrides));
    }

    private function supplier(): Supplier
    {
        return Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
    }

    private function package(Game $game, array $overrides = []): Package
    {
        return Package::query()->create(array_merge([
            'game_id' => $game->id,
            'name' => '86 Diamonds',
            'denomination' => 86,
            'cost_price' => 1000,
            'standard_selling_price' => 1200,
            'supplier_id' => $this->supplier()->id,
            'supplier_package_ref' => 'A',
            'is_active' => true,
        ], $overrides));
    }

    private function tier(array $overrides = []): ResellerTier
    {
        return ResellerTier::query()->create(array_merge([
            'name' => 'Tier SS',
            'markup_percent' => 3,
            'is_active' => true,
            'show_on_price_list' => true,
            'sort_order' => 0,
        ], $overrides));
    }

    public function test_empty_when_no_tier_is_marked_show_on_price_list(): void
    {
        $this->primaryAffiliate();
        $this->game();

        $response = $this->getJson('/api/catalog/reseller-price-list');

        $response->assertOk();
        $this->assertSame(['tiers' => [], 'games' => []], $response->json());
    }

    public function test_hidden_on_a_non_owned_affiliate_brand_even_with_tiers_configured(): void
    {
        $this->primaryAffiliate();
        $this->tier();
        $this->package($this->game());

        $affiliateB = Affiliate::query()->create([
            'business_name' => 'Soloz Store', 'markup_pct' => 5, 'status' => 'active', 'is_owned' => false,
        ]);
        AffiliateDomain::query()->create([
            'affiliate_id' => $affiliateB->id, 'hostname' => 'solozstore.my',
            'status' => AffiliateDomainStatus::Active, 'is_primary' => true,
        ]);

        $response = $this->getJson('/api/catalog/reseller-price-list', ['X-Storefront-Host' => 'solozstore.my']);

        $response->assertOk();
        $this->assertSame(['tiers' => [], 'games' => []], $response->json());
    }

    public function test_shown_on_the_primary_brand_with_tiers_ordered_by_sort_order(): void
    {
        $this->primaryAffiliate();
        $this->package($this->game());
        $this->tier(['name' => 'Tier S', 'markup_percent' => 4, 'sort_order' => 1]);
        $this->tier(['name' => 'Tier SS', 'markup_percent' => 3, 'sort_order' => 0]);

        $response = $this->getJson('/api/catalog/reseller-price-list');

        $response->assertOk();
        $this->assertSame(['Tier SS', 'Tier S'], collect($response->json('tiers'))->pluck('name')->all());
    }

    public function test_shown_on_an_owned_non_primary_affiliate_brand(): void
    {
        $this->primaryAffiliate();
        $this->package($this->game());
        $this->tier();

        $ownedBrand = Affiliate::query()->create([
            'business_name' => 'House Brand', 'markup_pct' => 0, 'status' => 'active', 'is_owned' => true,
        ]);
        AffiliateDomain::query()->create([
            'affiliate_id' => $ownedBrand->id, 'hostname' => 'housebrand.my',
            'status' => AffiliateDomainStatus::Active, 'is_primary' => true,
        ]);

        $response = $this->getJson('/api/catalog/reseller-price-list', ['X-Storefront-Host' => 'housebrand.my']);

        $response->assertOk();
        $this->assertNotEmpty($response->json('tiers'));
    }

    public function test_excludes_a_tier_not_marked_show_on_price_list_even_with_the_lowest_markup(): void
    {
        $this->primaryAffiliate();
        $this->package($this->game());
        $this->tier(['name' => 'Tier SS', 'markup_percent' => 3, 'sort_order' => 0]);
        // A special/negotiated 0% tier — cheapest of all, deliberately not public.
        $this->tier(['name' => 'Tier SSS', 'markup_percent' => 0, 'show_on_price_list' => false, 'sort_order' => 1]);

        $response = $this->getJson('/api/catalog/reseller-price-list');

        $response->assertOk();
        $names = collect($response->json('tiers'))->pluck('name')->all();
        $this->assertSame(['Tier SS'], $names);
    }

    public function test_price_matches_the_real_order_time_formula(): void
    {
        $this->primaryAffiliate();
        $this->package($this->game(), ['cost_price' => 1000, 'standard_selling_price' => 1200]);
        $this->tier(['markup_percent' => 5]);

        $response = $this->getJson('/api/catalog/reseller-price-list');

        $response->assertOk();
        // (int) round(1000 * 1.05) == 1050 — same formula
        // OrderPricingResolver::resolveResellerWallet() charges at real
        // order time.
        $this->assertSame(1050, $response->json('games.0.prices.0.price_sen'));
    }

    public function test_never_exposes_cost_price_or_internal_ids(): void
    {
        $this->primaryAffiliate();
        $this->package($this->game());
        $this->tier();

        $response = $this->getJson('/api/catalog/reseller-price-list');

        $response->assertOk();
        $game = $response->json('games.0');
        $this->assertSame(['game_name', 'package_name', 'prices'], array_keys($game));
        $this->assertSame(['tier_id', 'price_sen'], array_keys($game['prices'][0]));
    }
}
