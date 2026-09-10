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
use App\Services\Order\DeliveryStatus;
use App\Services\Reseller\ResellerApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** ADR-074 decision 3 + ADR-084 PR-1/PR-2: POST/GET /api/reseller/v1/orders. */
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

        // ADR-084 PR-1 decision 5 — fresh 201, exact replay 200 + header.
        $first->assertCreated();
        $first->assertHeaderMissing('Idempotent-Replayed');
        $second->assertOk();
        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('order_number'), $second->json('order_number'));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(10000 - 1100, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
    }

    public function test_409s_when_the_same_key_is_reused_with_a_different_payload(): void
    {
        Queue::fake();
        $this->makePackage();
        [$reseller, $key] = $this->makeFundedReseller();

        $this->postJson('/api/reseller/v1/orders', [
            'product_code' => 'MLMY-14', 'player_id' => 'alice', 'idempotency_key' => 'reused-key',
        ], $this->authHeaders($key))->assertCreated();

        $conflict = $this->postJson('/api/reseller/v1/orders', [
            'product_code' => 'MLMY-14', 'player_id' => 'bob', 'idempotency_key' => 'reused-key',
        ], $this->authHeaders($key));

        $conflict->assertStatus(409);
        $conflict->assertJsonPath('error', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(10000 - 1100, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
    }

    public function test_a_missing_required_field_is_a_validation_failed_envelope(): void
    {
        [, $key] = $this->makeFundedReseller();

        $this->postJson('/api/reseller/v1/orders', [
            'product_code' => 'MLMY-14', 'idempotency_key' => 'missing-player-id',
        ], $this->authHeaders($key))
            ->assertStatus(422)
            ->assertJsonPath('error', 'VALIDATION_FAILED')
            ->assertJsonPath('details.player_id.0', fn ($m) => is_string($m));
    }

    /** No manual pre-check in the controller for this — proves the NoResellerTierAssignedException path (thrown by placeOrder() itself) is actually wired to a 422, not just theoretically reachable. */
    public function test_422s_when_reseller_has_no_tier_assigned(): void
    {
        $this->primaryAffiliate();
        $this->makePackage();
        $reseller = Reseller::query()->create(['business_name' => 'No Tier', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);
        $key = app(ResellerApiKeyService::class)->issue($reseller, 'Test key')['plainText'];

        $response = $this->postJson('/api/reseller/v1/orders', [
            'product_code' => 'MLMY-14', 'player_id' => '1', 'idempotency_key' => 'no-tier-test',
        ], $this->authHeaders($key));

        $response->assertStatus(422)->assertJsonPath('error', 'NO_TIER_ASSIGNED');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_422s_for_an_unknown_product_code(): void
    {
        [$reseller, $key] = $this->makeFundedReseller();

        $response = $this->postJson('/api/reseller/v1/orders', [
            'product_code' => 'NOPE-14', 'player_id' => '1', 'idempotency_key' => 'unknown-code-test',
        ], $this->authHeaders($key));

        $response->assertStatus(422)->assertJsonPath('error', 'UNKNOWN_PRODUCT_CODE');
    }

    public function test_422s_on_insufficient_balance(): void
    {
        $this->makePackage(costPrice: 100000);
        [$reseller, $key] = $this->makeFundedReseller(walletBalance: 100);

        $response = $this->postJson('/api/reseller/v1/orders', [
            'product_code' => 'MLMY-14', 'player_id' => '1', 'idempotency_key' => 'insufficient-balance-test',
        ], $this->authHeaders($key));

        $response->assertStatus(422)->assertJsonPath('error', 'INSUFFICIENT_BALANCE');
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

        $response->assertNotFound()->assertJsonPath('error', 'ORDER_NOT_FOUND');
    }

    public function test_store_requires_a_valid_api_key(): void
    {
        $this->postJson('/api/reseller/v1/orders', [
            'product_code' => 'MLMY-14', 'player_id' => '1', 'idempotency_key' => 'no-auth-test',
        ])->assertUnauthorized()->assertJsonPath('error', 'MISSING_API_KEY');
    }

    // ── ADR-084 PR-2: GET /v1/orders ──────────────────────────────────

    private function order(Reseller $reseller, array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'wallet_reseller_id' => $reseller->id,
            'payment_method' => 'wallet',
        ], $overrides));
    }

    public function test_index_lists_only_the_callers_own_orders_newest_first(): void
    {
        [$owner, $key] = $this->makeFundedReseller();
        [$other] = $this->makeFundedReseller();

        $older = $this->order($owner, ['order_number' => 'PG-OLDER']);
        $newer = $this->order($owner, ['order_number' => 'PG-NEWER']);
        $this->order($other, ['order_number' => 'PG-OTHER']);

        $response = $this->getJson('/api/reseller/v1/orders', $this->authHeaders($key));

        $response->assertOk();
        $response->assertJsonCount(2, 'items');
        $response->assertJsonPath('items.0.order_number', 'PG-NEWER');
        $response->assertJsonPath('items.1.order_number', 'PG-OLDER');
        $response->assertJsonPath('next_cursor', null);
        $response->assertJsonMissingPath('items.0.cost_price');
    }

    public function test_index_paginates_with_an_opaque_cursor(): void
    {
        [$reseller, $key] = $this->makeFundedReseller();
        foreach (range(1, 5) as $i) {
            $this->order($reseller, ['order_number' => "PG-{$i}"]);
        }

        $first = $this->getJson('/api/reseller/v1/orders?limit=2', $this->authHeaders($key));
        $first->assertOk()->assertJsonCount(2, 'items');
        $this->assertNotNull($first->json('next_cursor'));

        $cursor = urlencode($first->json('next_cursor'));
        $second = $this->getJson("/api/reseller/v1/orders?limit=2&cursor={$cursor}", $this->authHeaders($key));
        $second->assertOk()->assertJsonCount(2, 'items');

        $firstNumbers = array_column($first->json('items'), 'order_number');
        $secondNumbers = array_column($second->json('items'), 'order_number');
        $this->assertEmpty(array_intersect($firstNumbers, $secondNumbers));

        $cursor2 = urlencode($second->json('next_cursor'));
        $third = $this->getJson("/api/reseller/v1/orders?limit=2&cursor={$cursor2}", $this->authHeaders($key));
        $third->assertOk()->assertJsonCount(1, 'items');
        $third->assertJsonPath('next_cursor', null);
    }

    public function test_index_filters_by_delivery_status(): void
    {
        [$reseller, $key] = $this->makeFundedReseller();
        $this->order($reseller, ['order_number' => 'PG-DONE', 'delivery_status' => DeliveryStatus::Delivered]);
        $this->order($reseller, ['order_number' => 'PG-FAIL', 'delivery_status' => DeliveryStatus::Failed]);

        $response = $this->getJson('/api/reseller/v1/orders?status=failed', $this->authHeaders($key));

        $response->assertOk()->assertJsonCount(1, 'items');
        $response->assertJsonPath('items.0.order_number', 'PG-FAIL');
    }

    public function test_index_filters_by_created_after(): void
    {
        [$reseller, $key] = $this->makeFundedReseller();
        $this->order($reseller, ['order_number' => 'PG-OLD', 'created_at' => '2026-01-01T00:00:00Z']);
        $this->order($reseller, ['order_number' => 'PG-NEW', 'created_at' => '2026-09-01T00:00:00Z']);

        $response = $this->getJson('/api/reseller/v1/orders?created_after=2026-06-01T00:00:00Z', $this->authHeaders($key));

        $response->assertOk()->assertJsonCount(1, 'items');
        $response->assertJsonPath('items.0.order_number', 'PG-NEW');
    }

    public function test_index_rejects_an_over_cap_limit_with_a_validation_envelope(): void
    {
        [, $key] = $this->makeFundedReseller();

        $this->getJson('/api/reseller/v1/orders?limit=500', $this->authHeaders($key))
            ->assertStatus(422)
            ->assertJsonPath('error', 'VALIDATION_FAILED')
            ->assertJsonPath('details.limit.0', fn ($m) => is_string($m));
    }

    public function test_index_rejects_an_unknown_status_with_a_validation_envelope(): void
    {
        [, $key] = $this->makeFundedReseller();

        $this->getJson('/api/reseller/v1/orders?status=teleported', $this->authHeaders($key))
            ->assertStatus(422)
            ->assertJsonPath('error', 'VALIDATION_FAILED');
    }

    public function test_index_returns_an_empty_page_for_a_reseller_with_no_orders(): void
    {
        [, $key] = $this->makeFundedReseller();

        $this->getJson('/api/reseller/v1/orders', $this->authHeaders($key))
            ->assertOk()
            ->assertExactJson(['items' => [], 'next_cursor' => null]);
    }

    public function test_index_requires_a_valid_api_key(): void
    {
        $this->getJson('/api/reseller/v1/orders')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'MISSING_API_KEY');
    }
}
