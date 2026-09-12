<?php

namespace Tests\Feature\Http\Controllers\ResellerApi;

use App\Http\Controllers\CatalogController;
use App\Models\Game;
use App\Models\Package;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Models\Supplier;
use App\Services\Reseller\ResellerApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ADR-074 decision 3 + ADR-084 PR-1: GET /api/reseller/v1/catalog. */
class CatalogControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeReseller(float $markupPercent = 10): array
    {
        $tier = ResellerTier::query()->create(['name' => 'Gold', 'markup_percent' => $markupPercent, 'is_active' => true, 'sort_order' => 1]);
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'reseller_tier_id' => $tier->id, 'is_active' => true]);
        $issued = app(ResellerApiKeyService::class)->issue($reseller, 'Test key');

        return [$reseller, $issued['plainText']];
    }

    private function authHeaders(string $key): array
    {
        return ['Authorization' => "Bearer {$key}"];
    }

    private function package(Game $game, string $name, int $denomination, int $cost, int $standard): void
    {
        $supplier = Supplier::query()->firstOrCreate(
            ['slug' => 'gamevion'],
            ['name' => 'Gamevion', 'api_config' => [], 'currency' => 'MYR'],
        );

        Package::query()->create([
            'game_id' => $game->id, 'name' => $name, 'denomination' => $denomination,
            'cost_price' => $cost, 'standard_selling_price' => $standard,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'REF-'.$denomination, 'is_active' => true,
        ]);
    }

    public function test_groups_priced_packages_by_game(): void
    {
        [, $key] = $this->makeReseller(markupPercent: 10);
        $game = Game::query()->create(['name' => 'Mobile Legends Malaysia', 'slug' => 'mlbb-my', 'reseller_code' => 'MLMY', 'is_active' => true]);
        $this->package($game, '14 Diamond', 14, 1000, 1200);
        $this->package($game, '86 Diamond', 86, 5000, 6000);

        $response = $this->getJson('/api/reseller/v1/catalog', $this->authHeaders($key));

        $response->assertOk();
        $response->assertExactJson(['games' => [[
            'code' => 'MLMY',
            'name' => 'Mobile Legends Malaysia',
            'packages' => [
                ['code' => 'MLMY-14', 'name' => '14 Diamond', 'price_sen' => 1100], // 1000 * 1.10
                ['code' => 'MLMY-86', 'name' => '86 Diamond', 'price_sen' => 5500], // 5000 * 1.10
            ],
        ]]]);
    }

    public function test_excludes_games_without_a_reseller_code(): void
    {
        [, $key] = $this->makeReseller();
        $game = Game::query()->create(['name' => 'Free Fire', 'slug' => 'ff', 'reseller_code' => null, 'is_active' => true]);
        $this->package($game, '100 Diamonds', 100, 500, 600);

        $response = $this->getJson('/api/reseller/v1/catalog', $this->authHeaders($key));

        $response->assertOk();
        $this->assertSame([], $response->json('games'));
    }

    public function test_never_leaks_cost_price_or_package_id(): void
    {
        [, $key] = $this->makeReseller();
        $game = Game::query()->create(['name' => 'Mobile Legends Malaysia', 'slug' => 'mlbb-my', 'reseller_code' => 'MLMY', 'is_active' => true]);
        $this->package($game, '14 Diamond', 14, 1000, 1200);

        $body = $this->getJson('/api/reseller/v1/catalog', $this->authHeaders($key))->json();

        $encoded = json_encode($body);
        $this->assertStringNotContainsString('cost_price', $encoded);
        $this->assertStringNotContainsString('markup_percent', $encoded);
        $this->assertStringNotContainsString('supplier_package_ref', $encoded);
        $this->assertArrayNotHasKey('id', $body['games'][0]['packages'][0]);
    }

    public function test_422_no_tier_assigned_carries_the_stable_error_code(): void
    {
        $reseller = Reseller::query()->create(['business_name' => 'No Tier', 'is_active' => true]);
        $key = app(ResellerApiKeyService::class)->issue($reseller, 'Test key')['plainText'];

        $this->getJson('/api/reseller/v1/catalog', $this->authHeaders($key))
            ->assertStatus(422)
            ->assertJsonPath('error', 'NO_TIER_ASSIGNED');
    }

    public function test_missing_key_carries_the_stable_error_code(): void
    {
        $this->getJson('/api/reseller/v1/catalog')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'MISSING_API_KEY');
    }

    public function test_invalid_key_carries_the_stable_error_code(): void
    {
        $this->getJson('/api/reseller/v1/catalog', $this->authHeaders('pgrk_not-real'))
            ->assertUnauthorized()
            ->assertJsonPath('error', 'INVALID_API_KEY');
    }

    public function test_rejects_a_deactivated_reseller(): void
    {
        [$reseller, $key] = $this->makeReseller();
        $reseller->update(['is_active' => false]);

        $this->getJson('/api/reseller/v1/catalog', $this->authHeaders($key))
            ->assertForbidden()
            ->assertJsonPath('error', 'RESELLER_INACTIVE');
    }

    /** A1 hardening: a tier's priced catalog is cached — a cost_price change is stale until invalidated. */
    public function test_priced_catalog_is_cached_per_tier(): void
    {
        [, $key] = $this->makeReseller(markupPercent: 10);
        $game = Game::query()->create(['name' => 'Mobile Legends Malaysia', 'slug' => 'mlbb-my', 'reseller_code' => 'MLMY', 'is_active' => true]);
        $this->package($game, '14 Diamond', 14, 1000, 1200);

        $first = $this->getJson('/api/reseller/v1/catalog', $this->authHeaders($key));
        $first->assertJsonPath('games.0.packages.0.price_sen', 1100); // 1000 * 1.10

        Package::query()->first()->update(['cost_price' => 2000]);

        $second = $this->getJson('/api/reseller/v1/catalog', $this->authHeaders($key));
        $second->assertJsonPath('games.0.packages.0.price_sen', 1100); // still cached
    }

    /** A1 hardening: forgetPricedCache() (the catalog-write choke point) bypasses the stale cache. */
    public function test_priced_catalog_cache_is_invalidated_by_forget_priced_cache(): void
    {
        [, $key] = $this->makeReseller(markupPercent: 10);
        $game = Game::query()->create(['name' => 'Mobile Legends Malaysia', 'slug' => 'mlbb-my', 'reseller_code' => 'MLMY', 'is_active' => true]);
        $this->package($game, '14 Diamond', 14, 1000, 5000);

        $this->getJson('/api/reseller/v1/catalog', $this->authHeaders($key));

        Package::query()->first()->update(['cost_price' => 2000]);
        CatalogController::forgetIndexCache();

        $this->getJson('/api/reseller/v1/catalog', $this->authHeaders($key))
            ->assertJsonPath('games.0.packages.0.price_sen', 2200); // 2000 * 1.10
    }

    /** A1 hardening: two tiers each get their own priced cache entry, never another's price. */
    public function test_priced_catalog_cache_is_scoped_per_tier(): void
    {
        [, $keyA] = $this->makeReseller(markupPercent: 10);
        [, $keyB] = $this->makeReseller(markupPercent: 20);
        $game = Game::query()->create(['name' => 'Mobile Legends Malaysia', 'slug' => 'mlbb-my', 'reseller_code' => 'MLMY', 'is_active' => true]);
        $this->package($game, '14 Diamond', 14, 1000, 1200);

        $this->getJson('/api/reseller/v1/catalog', $this->authHeaders($keyA))
            ->assertJsonPath('games.0.packages.0.price_sen', 1100);
        $this->getJson('/api/reseller/v1/catalog', $this->authHeaders($keyB))
            ->assertJsonPath('games.0.packages.0.price_sen', 1200);
    }

    public function test_rejects_a_revoked_key(): void
    {
        [$reseller, $key] = $this->makeReseller();
        $service = app(ResellerApiKeyService::class);
        $service->revoke($reseller->apiKeys()->first());

        $this->getJson('/api/reseller/v1/catalog', $this->authHeaders($key))
            ->assertUnauthorized()
            ->assertJsonPath('error', 'INVALID_API_KEY');
    }
}
