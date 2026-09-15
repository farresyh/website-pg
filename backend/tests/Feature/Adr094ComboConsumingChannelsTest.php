<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Models\ResellerWhatsAppGroup;
use App\Models\Supplier;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use App\Services\Pricing\OrderPricingResolver;
use App\Services\Reseller\Bot\ResellerBotService;
use App\Services\Reseller\ResellerApiKeyService;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-094 decision 14 verification (2026-09-15, post-Phase-4). A code
 * audit across all 5 consuming channels found none of them special-case
 * `is_combo` — storefront/Affiliate (`CheckoutController`) and Reseller
 * (`ResellerApi\OrderController`/`ResellerBotService`, both via
 * `resolveByCode()`) all resolve a Package generically by
 * denomination/id and hand it to the same `Order::create()` +
 * `OrderFulfillmentService::fulfill()` path a native SKU uses (decision
 * 2's "denomination is the identity" + decision 7's generic leg-engine
 * are exactly why). Every response shape (`CheckoutController`'s own
 * reply, `TrackOrderController`, `ResellerOrderPayload`) was already
 * narrow before this ADR existed, so decision 12's opacity requirement
 * falls out for free too.
 *
 * This test proves that reading, not asserting: a real combo Package,
 * placed through each real entry point, actually reaches Delivered and
 * never leaks `is_combo`/`components` anywhere a customer or reseller
 * can see. Reseller Portal carries no order-placement surface of its
 * own (confirmed separately, grep — zero catalog/order calls in
 * `reseller/`), so it has nothing to verify here.
 */
class Adr094ComboConsumingChannelsTest extends TestCase
{
    use RefreshDatabase;

    private const SUPPLIER_SLUG = 'test-supplier';

    private const COMPONENT_A_DENOMINATION = 7502;

    private const COMPONENT_B_DENOMINATION = 2976;

    private function comboPackage(): Package
    {
        $supplier = Supplier::query()->firstOrCreate(
            ['slug' => self::SUPPLIER_SLUG],
            ['name' => 'Test Supplier', 'api_config' => [], 'currency' => 'MYR'],
        );
        $game = Game::query()->create([
            'name' => 'MLBB Malaysia', 'slug' => 'mlbb-malaysia-'.uniqid(),
            'is_active' => true, 'reseller_code' => 'MLMY',
        ]);

        $a = Package::query()->create([
            'game_id' => $game->id, 'name' => '7502 Diamonds', 'denomination' => self::COMPONENT_A_DENOMINATION,
            'cost_price' => 60000, 'standard_selling_price' => 66000, 'markup_percent' => 10,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-7502', 'is_active' => true,
        ]);
        $b = Package::query()->create([
            'game_id' => $game->id, 'name' => '2976 Diamonds', 'denomination' => self::COMPONENT_B_DENOMINATION,
            'cost_price' => 25000, 'standard_selling_price' => 27500, 'markup_percent' => 10,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-2976', 'is_active' => true,
        ]);

        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => '10478 Diamonds', 'is_combo' => true,
            'denomination' => self::COMPONENT_A_DENOMINATION + self::COMPONENT_B_DENOMINATION,
            'cost_price' => 85000, 'standard_selling_price' => 93500, 'markup_percent' => 10,
            'is_active' => true,
        ]);
        $combo->components()->attach($a->id, ['quantity' => 1, 'sort_order' => 0]);
        $combo->components()->attach($b->id, ['quantity' => 1, 'sort_order' => 1]);

        return $combo->fresh();
    }

    /**
     * Same queued-success fake `OrderFulfillmentServiceComboTest` uses —
     * fulfillment mechanics themselves (leg loop, idempotency, ledger)
     * are already proven there; this file only proves each consuming
     * channel hands a combo Order to that engine correctly.
     */
    private function bindSuccessfulAdapter(): void
    {
        $adapter = new class implements SupplierAdapter
        {
            private int $i = 0;

            public function checkBalance(): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function listProducts(): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function createOrder(SupplierOrderRequest $request): SupplierResponse
            {
                return SupplierResponse::success(['supplier_ref' => 'SREF-'.(++$this->i), 'price' => 480]);
            }

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used in this test');
            }
        };

        $this->app->bind('supplier-adapter.'.self::SUPPLIER_SLUG, fn () => $adapter);
    }

    // ── Storefront (+ Affiliate, same codebase) ─────────────────────

    public function test_storefront_checkout_creates_and_fulfills_a_combo_order_with_zero_leakage(): void
    {
        $this->primaryAffiliate();
        $combo = $this->comboPackage();
        PaymentMethod::query()->create([
            'channel_code' => 'FPX_ABMB', 'label' => 'Test Channel', 'category' => 'fpx', 'gateway' => 'chip',
            'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 210,
        ]);
        $gateway = new class implements PaymentGateway
        {
            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                return PaymentResponse::success(['payment_request_id' => 'pr-combo-e2e']);
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                return PaymentResponse::success(['payment_request_id' => $paymentRequestId, 'actions' => []]);
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                throw new RuntimeException('not used in this test');
            }

            public function parseWebhookEvent(array $payload): PaymentWebhookEvent
            {
                throw new RuntimeException('not used in this test');
            }
        };
        $this->app->bind('payment-gateway.chip', fn () => $gateway);

        $response = $this->postJson('/api/checkout', [
            'game_id' => $combo->game_id,
            'package_id' => $combo->id,
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Buyer One',
            'customer_phone' => '0123456789',
            'player_id' => '123456789',
            'channel_code' => 'FPX_ABMB',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('final_amount', 93500 + 210);
        // decision 12: the response is already this narrow by
        // construction (CheckoutController::buildCheckoutResponse()
        // hand-builds 4 keys, never $order->toArray()) — asserting the
        // exact key set, not just individually missing paths, is the
        // strongest form of "nothing new ever leaks here."
        $this->assertSame(
            ['order_number', 'final_amount', 'payment_status', 'payment_actions'],
            array_keys($response->json()),
        );

        $order = Order::query()->firstOrFail();
        $this->assertSame($combo->id, $order->package_id);
        $this->assertSame(93500, $order->selling_price); // combo's own summed price + 0% affiliate markup, zero special-casing
        $this->assertNull($order->supplier_id);
        $this->assertNull($order->supplier_product_ref);

        // Real payment webhook mechanics are its own tested concern —
        // mark Paid and run the same fulfillment engine FulfillOrderJob
        // would, proving the seam between "checkout created this order"
        // and "the combo fulfillment engine can consume it" holds.
        $order->update(['payment_status' => PaymentStatus::Paid->value]);
        $this->bindSuccessfulAdapter();
        $result = app(OrderFulfillmentService::class)->fulfill($order->fresh());

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $this->assertCount(2, OrderDeliveryLeg::query()->where('order_id', $order->id)->get());

        // decision 12's real proof point — the public page a real
        // customer lands on to check their order.
        $tracked = $this->getJson("/api/track-order/{$order->order_number}");
        $tracked->assertOk();
        $tracked->assertJsonPath('delivery_status', 'delivered');
        $tracked->assertJsonPath('package_name', $combo->name);
        $tracked->assertJsonMissingPath('is_combo');
        $tracked->assertJsonMissingPath('components');
    }

    // ── Reseller REST API ─────────────────────────────────────────────

    private function fundedReseller(int $walletBalanceSen = 200_000, float $markupPercent = 10): Reseller
    {
        $this->primaryAffiliate();
        $tier = ResellerTier::query()->create(['name' => 'Gold', 'markup_percent' => $markupPercent, 'is_active' => true, 'sort_order' => 1]);
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'reseller_tier_id' => $tier->id, 'is_active' => true]);
        $ledger = app(LedgerService::class);
        $ledger->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);
        $ledger->credit(LedgerOwnerType::ResellerWallet, $reseller->id, $walletBalanceSen, 'wallet_topup');

        return $reseller;
    }

    public function test_reseller_api_places_and_fulfills_a_combo_order_with_zero_leakage(): void
    {
        Queue::fake();
        $combo = $this->comboPackage();
        $reseller = $this->fundedReseller(markupPercent: 10);
        $key = app(ResellerApiKeyService::class)->issue($reseller, 'Test key')['plainText'];

        // The same pricing seam ResellerOrderPlacementService actually
        // charges through, called independently here — proves the
        // combo's own already-correct cost_price/standard_selling_price
        // (ComboPricingService, Phase 2) feeds the reseller wallet
        // formula unmodified, without re-deriving that formula by hand.
        $expectedPriceSen = (int) app(OrderPricingResolver::class)
            ->resolveResellerWallet($combo->cost_price, $combo->standard_selling_price, 10.0)
            ->sellingPriceSen;

        $response = $this->postJson('/api/reseller/v1/orders', [
            'product_code' => 'MLMY-10478',
            'player_id' => '123456789',
            'idempotency_key' => (string) Str::uuid(),
        ], ['Authorization' => "Bearer {$key}"]);

        $response->assertCreated();
        $response->assertJsonPath('product_code', 'MLMY-10478');
        $response->assertJsonPath('price_sen', $expectedPriceSen);
        $response->assertJsonMissingPath('is_combo');
        $response->assertJsonMissingPath('components');

        $order = Order::query()->where('wallet_reseller_id', $reseller->id)->firstOrFail();
        $this->assertSame($combo->id, $order->package_id);
        $this->assertNull($order->supplier_id);
        $this->assertNull($order->supplier_product_ref);
        $this->assertSame(200_000 - $expectedPriceSen, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id));

        $this->bindSuccessfulAdapter();
        $result = app(OrderFulfillmentService::class)->fulfill($order->fresh());
        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);

        $show = $this->getJson("/api/reseller/v1/orders/{$order->order_number}", ['Authorization' => "Bearer {$key}"]);
        $show->assertOk();
        $show->assertJsonPath('delivery_status', 'delivered');
        $show->assertJsonMissingPath('is_combo');
        $show->assertJsonMissingPath('components');
    }

    // ── Reseller WhatsApp Bot ───────────────────────────────────────────

    public function test_reseller_bot_places_and_fulfills_a_combo_order(): void
    {
        Queue::fake();
        $combo = $this->comboPackage();
        $reseller = $this->fundedReseller(markupPercent: 10);
        ResellerWhatsAppGroup::query()->create(['reseller_id' => $reseller->id, 'whatsapp_group_id' => 'g1@g.us', 'is_active' => true]);

        app(ResellerBotService::class)->handle('g1@g.us', '.order MLMY-10478 123456789', 'msg-1');

        $order = Order::query()->where('wallet_reseller_id', $reseller->id)->first();
        $this->assertNotNull($order, '.order MLMY-10478 should have resolved to the combo and placed it — same resolveByCode() the API test above uses.');
        $this->assertSame($combo->id, $order->package_id);
        $this->assertSame(
            200_000 - $order->selling_price,
            app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id),
        );

        $this->bindSuccessfulAdapter();
        $result = app(OrderFulfillmentService::class)->fulfill($order->fresh());
        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $this->assertCount(2, OrderDeliveryLeg::query()->where('order_id', $order->id)->get());
    }
}
