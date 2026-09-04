<?php

namespace Tests\Feature\Http\Controllers\ResellerApi;

use App\Models\Game;
use App\Models\Package;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Models\Supplier;
use App\Services\Reseller\ResellerApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ADR-074 decision 3: GET /api/reseller/v1/catalog. */
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

    public function test_lists_priced_packages_from_reseller_coded_games(): void
    {
        [$reseller, $key] = $this->makeReseller(markupPercent: 10);
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Mobile Legends Malaysia', 'slug' => 'mlbb-my', 'reseller_code' => 'MLMY', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'denomination' => 14,
            'cost_price' => 1000, 'standard_selling_price' => 1200,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);

        $response = $this->getJson('/api/reseller/v1/catalog', $this->authHeaders($key));

        $response->assertOk();
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('MLMY-14', $items[0]['code']);
        $this->assertSame('14 Diamond', $items[0]['name']);
        $this->assertSame(1100, $items[0]['price_sen']); // 1000 * 1.10
    }

    public function test_excludes_games_without_a_reseller_code(): void
    {
        [$reseller, $key] = $this->makeReseller();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire', 'slug' => 'ff', 'reseller_code' => null, 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'denomination' => 100,
            'cost_price' => 500, 'standard_selling_price' => 600,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);

        $response = $this->getJson('/api/reseller/v1/catalog', $this->authHeaders($key));

        $response->assertOk();
        $this->assertCount(0, $response->json('items'));
    }

    public function test_never_leaks_cost_price_or_package_id(): void
    {
        [$reseller, $key] = $this->makeReseller();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Mobile Legends Malaysia', 'slug' => 'mlbb-my', 'reseller_code' => 'MLMY', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'denomination' => 14,
            'cost_price' => 1000, 'standard_selling_price' => 1200,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);

        $response = $this->getJson('/api/reseller/v1/catalog', $this->authHeaders($key));

        $response->assertJsonMissingPath('items.0.id');
        $response->assertJsonMissingPath('items.0.cost_price');
        $response->assertJsonMissingPath('items.0.supplier_package_ref');
    }

    public function test_422s_when_reseller_has_no_tier_assigned(): void
    {
        $reseller = Reseller::query()->create(['business_name' => 'No Tier', 'is_active' => true]);
        $key = app(ResellerApiKeyService::class)->issue($reseller, 'Test key')['plainText'];

        $this->getJson('/api/reseller/v1/catalog', $this->authHeaders($key))->assertStatus(422);
    }

    public function test_requires_a_valid_api_key(): void
    {
        $this->getJson('/api/reseller/v1/catalog')->assertUnauthorized();
        $this->getJson('/api/reseller/v1/catalog', $this->authHeaders('pgrk_not-real'))->assertUnauthorized();
    }

    public function test_rejects_a_deactivated_reseller(): void
    {
        [$reseller, $key] = $this->makeReseller();
        $reseller->update(['is_active' => false]);

        $this->getJson('/api/reseller/v1/catalog', $this->authHeaders($key))->assertForbidden();
    }

    public function test_rejects_a_revoked_key(): void
    {
        $tier = ResellerTier::query()->create(['name' => 'Gold', 'markup_percent' => 10, 'is_active' => true, 'sort_order' => 1]);
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'reseller_tier_id' => $tier->id, 'is_active' => true]);
        $service = app(ResellerApiKeyService::class);
        $issued = $service->issue($reseller, 'Test key');
        $service->revoke($issued['key']);

        $this->getJson('/api/reseller/v1/catalog', $this->authHeaders($issued['plainText']))->assertUnauthorized();
    }
}
