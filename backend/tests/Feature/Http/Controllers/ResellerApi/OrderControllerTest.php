<?php

namespace Tests\Feature\Http\Controllers\ResellerApi;

use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Models\Supplier;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\ResellerApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** ADR-074 decision 3: POST/GET /api/reseller/v1/orders. */
class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makePackage(int $costPrice = 1000): Package
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Mobile Legends Malaysia', 'slug' => 'mlbb-my', 'reseller_code' => 'MLMY', 'is_active' => true]);

        return Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'denomination' => 14,
            'cost_price' => $costPrice, 'standard_selling_price' => $costPrice + 200,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
    }

    /**
     * @return array{0: Reseller, 1: string}
     */
    private function makeFundedReseller(int $walletBalance = 10000, float $markupPercent = 10): array
    {
        $this->primaryAffiliate();
        $tier = ResellerTier::query()->create(['name' => 'Gold', 'markup_percent' => $markupPercent, 'is_active' => true, 'sort_order' => 1]);
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'reseller_tier_id' => $tier->id, 'is_active' => true]);
        $ledger = app(LedgerService::class);
        $ledger->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);
        $ledger->credit(LedgerOwnerType::ResellerWallet, $reseller->id, $walletBalance, 'wallet_topup');
        $key = app(ResellerApiKeyService::class)->issue($reseller, 'Test key')['plainText'];

        return [$reseller, $key];
    }

    private function authHeaders(string $key): array
    {
        return ['Authorization' => "Bearer {$key}"];
    }

    public function test_places_an_order_and_debits_the_wallet(): void
    {
        Queue::fake();
        $package = $this->makePackage(costPrice: 1000);
        [$reseller, $key] = $this->makeFundedReseller(walletBalance: 10000, markupPercent: 10);

        $response = $this->postJson('/api/reseller/v1/orders', [
            'product_code' => 'MLMY-14',
            'player_id' => '123456789',
            'server_id' => '9999',
            'idempotency_key' => 'reseller-order-test-1',
        ], $this->authHeaders($key));

        $response->assertCreated();
        $response->assertJsonPath('product_code', 'MLMY-14');
        $response->assertJsonPath('price_sen', 1100); // 1000 * 1.10
        $response->assertJsonPath('payment_status', 'paid');

        $this->assertSame(10000 - 1100, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
        $this->assertDatabaseHas('orders', ['player_id' => '123456789', 'wallet_reseller_id' => $reseller->id]);
    }

    public function test_replays_the_same_order_on_a_repeated_idempotency_key(): void
    {
        Queue::fake();
        $this->makePackage();
        [$reseller, $key] = $this->makeFundedReseller();
        $payload = [
            'product_code' => 'MLMY-14', 'player_id' => '1', 'idempotency_key' => 'same-key-twice',
        ];

        $first = $this->postJson('/api/reseller/v1/orders', $payload, $this->authHeaders($key));
        $second = $this->postJson('/api/reseller/v1/orders', $payload, $this->authHeaders($key));

        $second->assertCreated();
        $this->assertSame($first->json('order_number'), $second->json('order_number'));
        $this->assertSame(1, Order::query()->count());
    }

    public function test_422s_for_an_unknown_product_code(): void
    {
        [$reseller, $key] = $this->makeFundedReseller();

        $response = $this->postJson('/api/reseller/v1/orders', [
            'product_code' => 'NOPE-14', 'player_id' => '1', 'idempotency_key' => 'unknown-code-test',
        ], $this->authHeaders($key));

        $response->assertStatus(422);
    }

    public function test_422s_on_insufficient_balance(): void
    {
        $this->makePackage(costPrice: 100000);
        [$reseller, $key] = $this->makeFundedReseller(walletBalance: 100);

        $response = $this->postJson('/api/reseller/v1/orders', [
            'product_code' => 'MLMY-14', 'player_id' => '1', 'idempotency_key' => 'insufficient-balance-test',
        ], $this->authHeaders($key));

        $response->assertStatus(422);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_show_returns_the_callers_own_order(): void
    {
        Queue::fake();
        $this->makePackage();
        [$reseller, $key] = $this->makeFundedReseller();
        $created = $this->postJson('/api/reseller/v1/orders', [
            'product_code' => 'MLMY-14', 'player_id' => '1', 'idempotency_key' => 'show-test-1',
        ], $this->authHeaders($key));
        $orderNumber = $created->json('order_number');

        $response = $this->getJson("/api/reseller/v1/orders/{$orderNumber}", $this->authHeaders($key));

        $response->assertOk();
        $response->assertJsonPath('order_number', $orderNumber);
    }

    public function test_show_404s_for_another_resellers_order(): void
    {
        Queue::fake();
        $this->makePackage();
        [$owner, $ownerKey] = $this->makeFundedReseller();
        $created = $this->postJson('/api/reseller/v1/orders', [
            'product_code' => 'MLMY-14', 'player_id' => '1', 'idempotency_key' => 'cross-reseller-test',
        ], $this->authHeaders($ownerKey));
        $orderNumber = $created->json('order_number');

        [$intruder, $intruderKey] = $this->makeFundedReseller();
        $response = $this->getJson("/api/reseller/v1/orders/{$orderNumber}", $this->authHeaders($intruderKey));

        $response->assertNotFound();
    }

    public function test_store_requires_a_valid_api_key(): void
    {
        $this->postJson('/api/reseller/v1/orders', [
            'product_code' => 'MLMY-14', 'player_id' => '1', 'idempotency_key' => 'no-auth-test',
        ])->assertUnauthorized();
    }
}
