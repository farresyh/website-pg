<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Jobs\FulfillOrderJob;
use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-096 — the admin "Check from Supplier"/"Check from Gateway"
 * manual-poll actions: a synchronous, read-only status-check call that
 * auto-applies a terminal outcome (through the same shared services
 * CheckSupplierDeliveryJob/ReconcilePendingPaymentsCommand use) and
 * enforces a cache-based cooldown afterward regardless of outcome.
 */
class OrderManualCheckTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
    }

    private function digiflazzSupplier(array $config = []): Supplier
    {
        return Supplier::query()->firstOrCreate(
            ['slug' => 'digiflazz-test'],
            ['name' => 'Digiflazz Test', 'currency' => 'IDR', 'api_config' => $config],
        );
    }

    private function pendingOrder(array $overrides = []): Order
    {
        $supplierId = $overrides['supplier_id'] ?? $this->digiflazzSupplier()->id;

        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-'.uniqid(),
            'reference_number' => 'REF-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'supplier_id' => $supplierId,
            'supplier_product_ref' => 'xld10',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Pending->value,
        ], $overrides));
    }

    private function bindSupplierAdapter(SupplierResponse $response): void
    {
        $this->app->bind('supplier-adapter.digiflazz-test', fn () => new class($response) implements SupplierAdapter
        {
            public function __construct(private readonly SupplierResponse $response) {}

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
                throw new RuntimeException('not used in this test');
            }

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                return $this->response;
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used in this test');
            }
        });
    }

    private function bindPaymentGateway(string $status, int $amountSen = 1100): void
    {
        $this->app->bind('payment-gateway.chip', fn () => new class($status, $amountSen) implements PaymentGateway
        {
            public function __construct(private readonly string $status, private readonly int $amountSen) {}

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                $status = match ($this->status) {
                    'paid' => PaymentStatus::Paid,
                    'error' => PaymentStatus::Failed,
                    default => PaymentStatus::Pending,
                };

                return PaymentResponse::success(['status' => $this->status, 'amount_sen' => $this->amountSen], status: $status);
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                throw new RuntimeException('not used in this test');
            }

            public function parseWebhookEvent(array $payload): PaymentWebhookEvent
            {
                throw new RuntimeException('not used in this test');
            }
        });
    }

    // --- Check from Supplier ---

    public function test_check_supplier_finalizes_a_pending_order_and_returns_the_raw_response(): void
    {
        $this->actingAsAdmin();
        $order = $this->pendingOrder();
        $this->bindSupplierAdapter(SupplierResponse::success(['supplier_ref' => 'DGFLZ-MANUAL-1', 'status' => 'Sukses']));

        $response = $this->postJson("/api/orders/{$order->id}/check-supplier");

        $response->assertOk();
        $response->assertJsonPath('delivery_status', DeliveryStatus::Delivered->value);
        $response->assertJsonPath('supplier_ref', 'DGFLZ-MANUAL-1');
        $response->assertJsonPath('result.type', 'plain');
        $response->assertJsonPath('result.outcome', 'success');
        $response->assertJsonPath('result.applied', true);
        $response->assertJsonPath('result.data.status', 'Sukses');
        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
    }

    public function test_check_supplier_leaves_a_still_pending_order_untouched(): void
    {
        $this->actingAsAdmin();
        $order = $this->pendingOrder();
        $this->bindSupplierAdapter(SupplierResponse::pending(['status' => 'Pending']));

        $response = $this->postJson("/api/orders/{$order->id}/check-supplier");

        $response->assertOk();
        $response->assertJsonPath('delivery_status', DeliveryStatus::Pending->value);
        $response->assertJsonPath('result.applied', false);
        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
    }

    public function test_check_supplier_rejects_an_order_that_is_not_pending(): void
    {
        $this->actingAsAdmin();
        $order = $this->pendingOrder(['delivery_status' => DeliveryStatus::Delivered->value]);

        $response = $this->postJson("/api/orders/{$order->id}/check-supplier");

        $response->assertUnprocessable();
    }

    public function test_check_supplier_enforces_a_cooldown_after_any_attempt(): void
    {
        $this->actingAsAdmin();
        $order = $this->pendingOrder();
        $this->bindSupplierAdapter(SupplierResponse::pending(['status' => 'Pending']));

        $this->postJson("/api/orders/{$order->id}/check-supplier")->assertOk();
        $second = $this->postJson("/api/orders/{$order->id}/check-supplier");

        $second->assertStatus(422);
        $this->assertGreaterThan(0, $second->json('retry_after_seconds'));
    }

    public function test_check_supplier_cooldown_is_overridable_per_supplier(): void
    {
        $this->actingAsAdmin();
        $order = $this->pendingOrder(['supplier_id' => $this->digiflazzSupplier(['manual_check_cooldown_seconds' => 0])->id]);
        $this->bindSupplierAdapter(SupplierResponse::pending(['status' => 'Pending']));

        $this->postJson("/api/orders/{$order->id}/check-supplier")->assertOk();
        $second = $this->postJson("/api/orders/{$order->id}/check-supplier");

        // A 0-second override means no real cooldown — the second call
        // should reach the adapter again rather than being blocked.
        $second->assertOk();
    }

    /** ADR-096 decision 3 — one button per order; the combo leg-loop happens inside the shared service. */
    public function test_check_supplier_checks_every_pending_leg_of_a_combo_order_in_one_call(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->digiflazzSupplier();
        $game = Game::query()->create(['name' => 'MLBB Manual Check', 'slug' => 'mlbb-manual-check-'.uniqid()]);
        $component = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Component', 'denomination' => 100,
            'cost_price' => 500, 'standard_selling_price' => 600, 'markup_percent' => 20,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'combo-sku-1',
        ]);
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true,
            'denomination' => 100, 'cost_price' => 500, 'standard_selling_price' => 600, 'markup_percent' => 20,
        ]);
        $combo->components()->attach($component->id, ['quantity' => 1, 'sort_order' => 0]);
        $order = $this->pendingOrder(['package_id' => $combo->id, 'supplier_id' => null, 'supplier_product_ref' => null]);
        $leg = OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $component->id,
            'supplier_id' => $supplier->id, 'leg_number' => 1, 'status' => DeliveryStatus::Pending->value,
        ]);
        $this->bindSupplierAdapter(SupplierResponse::success(['supplier_ref' => 'DGFLZ-COMBO-MANUAL-1']));

        $response = $this->postJson("/api/orders/{$order->id}/check-supplier");

        $response->assertOk();
        $response->assertJsonPath('result.type', 'combo');
        $response->assertJsonPath('result.legs.0.applied', true);
        $this->assertSame(DeliveryStatus::Delivered, $leg->fresh()->status);
        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
    }

    public function test_check_supplier_requires_authentication(): void
    {
        $order = $this->pendingOrder();

        $this->postJson("/api/orders/{$order->id}/check-supplier")->assertUnauthorized();
    }

    // --- Check from Gateway ---

    private function pendingPaymentOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
            'payment_gateway' => 'chip',
            'payment_ref' => 'chip_ref_'.uniqid(),
        ], $overrides));
    }

    public function test_check_gateway_marks_a_pending_order_paid_and_returns_the_raw_response(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $order = $this->pendingPaymentOrder();
        $this->bindPaymentGateway('paid');

        $response = $this->postJson("/api/orders/{$order->id}/check-gateway");

        $response->assertOk();
        $response->assertJsonPath('payment_status', PaymentStatus::Paid->value);
        $response->assertJsonPath('result.outcome', 'paid');
        $response->assertJsonPath('result.applied', true);
        $response->assertJsonPath('result.data.status', 'paid');
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        Queue::assertPushed(FulfillOrderJob::class, fn (FulfillOrderJob $job) => $job->order->id === $order->id);
    }

    public function test_check_gateway_marks_a_pending_order_failed(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $order = $this->pendingPaymentOrder();
        $this->bindPaymentGateway('error');

        $response = $this->postJson("/api/orders/{$order->id}/check-gateway");

        $response->assertOk();
        $response->assertJsonPath('payment_status', PaymentStatus::Failed->value);
        Queue::assertNotPushed(FulfillOrderJob::class);
    }

    public function test_check_gateway_rejects_an_order_that_is_not_pending(): void
    {
        $this->actingAsAdmin();
        $order = $this->pendingPaymentOrder(['payment_status' => PaymentStatus::Paid->value]);

        $response = $this->postJson("/api/orders/{$order->id}/check-gateway");

        $response->assertUnprocessable();
    }

    public function test_check_gateway_enforces_a_cooldown_after_any_attempt(): void
    {
        $this->actingAsAdmin();
        $order = $this->pendingPaymentOrder();
        $this->bindPaymentGateway('hold');

        $this->postJson("/api/orders/{$order->id}/check-gateway")->assertOk();
        $second = $this->postJson("/api/orders/{$order->id}/check-gateway");

        $second->assertStatus(422);
        $this->assertGreaterThan(0, $second->json('retry_after_seconds'));
    }

    public function test_check_gateway_requires_authentication(): void
    {
        $order = $this->pendingPaymentOrder();

        $this->postJson("/api/orders/{$order->id}/check-gateway")->assertUnauthorized();
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }
}
