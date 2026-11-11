<?php

namespace Tests\Feature\Http\Controllers\ResellerApi;

use App\Events\OrderStatusUpdated;
use App\Listeners\Reseller\DispatchResellerOrderWebhook;
use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Models\ResellerWebhookDelivery;
use App\Models\Supplier;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Reseller\ResellerApiKeyService;
use App\Services\Reseller\Webhook\ResellerWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The order's public wire shape is a contract with partners' code (ADR-084
 * decision 2). This pins it exactly — keys, order and types — on every surface
 * that carries it: POST (201 and the 200 replay), GET one, GET list, and the
 * delivery webhook body, plus the response headers the docs promise.
 * Documentation changes must never move it.
 */
class OrderWireShapeTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER_KEYS = [
        'order_number', 'product_code', 'player_id', 'server_id', 'price_sen',
        'payment_status', 'delivery_status', 'wallet_refunded', 'wallet_refund',
        'created_at', 'delivered_at',
    ];

    /** @return array{0: Reseller, 1: string} */
    private function fundedReseller(): array
    {
        $this->fakeOutboundDns();
        $supplier = Supplier::query()->create(['name' => 'S', 'slug' => 's', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Mobile Legends Malaysia', 'slug' => 'mlbb-my', 'reseller_code' => 'MLMY', 'is_active' => true]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'denomination' => 14,
            'cost_price' => 1000, 'standard_selling_price' => 1200,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        $this->primaryAffiliate();
        $tier = ResellerTier::query()->create(['name' => 'Gold', 'markup_percent' => 10, 'is_active' => true, 'sort_order' => 1]);
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'reseller_tier_id' => $tier->id, 'is_active' => true]);
        $ledger = app(LedgerService::class);
        $ledger->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);
        $ledger->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 10000, 'wallet_topup');
        app(ResellerWebhookService::class)->setEndpoint($reseller, 'https://example.test/hook');

        return [$reseller->fresh(), app(ResellerApiKeyService::class)->issue($reseller, 'k')['plainText']];
    }

    private function assertOrderShape(array $order): void
    {
        $this->assertSame(self::ORDER_KEYS, array_keys($order));
        $this->assertIsString($order['order_number']);
        $this->assertSame('MLMY-14', $order['product_code']);
        $this->assertSame('123456789', $order['player_id']);
        $this->assertNull($order['server_id']);
        $this->assertSame(1100, $order['price_sen']);
        $this->assertSame('paid', $order['payment_status']);
        $this->assertIsString($order['delivery_status']);
        $this->assertIsBool($order['wallet_refunded']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $order['created_at']);
    }

    public function test_every_surface_carries_the_same_exact_order_shape(): void
    {
        Queue::fake();
        [$reseller, $key] = $this->fundedReseller();
        $headers = ['Authorization' => "Bearer {$key}"];
        $body = ['product_code' => 'MLMY-14', 'player_id' => '123456789', 'idempotency_key' => 'wire-shape-1'];

        $createdResponse = $this->postJson('/api/reseller/v1/orders', $body, $headers)->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed')
            ->assertHeader('X-RateLimit-Limit', '60')->assertHeader('X-RateLimit-Remaining');
        $created = $createdResponse->json();
        $replayed = $this->postJson('/api/reseller/v1/orders', $body, $headers)->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')->json();
        $shown = $this->getJson("/api/reseller/v1/orders/{$created['order_number']}", $headers)->assertOk()
            ->assertHeader('X-RateLimit-Limit', '120')->assertHeader('X-RateLimit-Remaining')->json();
        $listed = $this->getJson('/api/reseller/v1/orders', $headers)->assertOk()->json();

        $this->assertOrderShape($created);
        $this->assertNull($created['wallet_refund']);
        $this->assertNull($created['delivered_at']);
        $this->assertSame($created, $replayed);
        $this->assertSame($created, $shown);
        $this->assertSame(['items', 'next_cursor'], array_keys($listed));
        $this->assertSame([$created], $listed['items']);

        // The webhook body is the same order plus three envelope fields.
        $order = Order::query()->where('order_number', $created['order_number'])->firstOrFail();
        $order->update(['delivery_status' => DeliveryStatus::Failed]);
        app(LedgerService::class)->credit(
            LedgerOwnerType::ResellerWallet, $reseller->id, 1100, 'wallet_refund',
            referenceType: 'order', referenceId: $order->id,
        );
        app(DispatchResellerOrderWebhook::class)->handle(new OrderStatusUpdated($order->fresh()));
        $payload = ResellerWebhookDelivery::query()->sole()->payload;

        $this->assertSame([...self::ORDER_KEYS, 'event', 'event_id', 'occurred_at'], array_keys($payload));
        $this->assertOrderShape(array_slice($payload, 0, count(self::ORDER_KEYS)));
        $this->assertSame('failed', $payload['delivery_status']);
        $this->assertTrue($payload['wallet_refunded']);
        $this->assertSame(['amount_sen', 'refunded_at'], array_keys($payload['wallet_refund']));
        $this->assertSame(1100, $payload['wallet_refund']['amount_sen']);
        $this->assertSame('order.failed', $payload['event']);

        $refundedShown = $this->getJson("/api/reseller/v1/orders/{$created['order_number']}", $headers)->assertOk()->json();
        $this->assertSame(array_slice($payload, 0, count(self::ORDER_KEYS)), $refundedShown);
    }
}
