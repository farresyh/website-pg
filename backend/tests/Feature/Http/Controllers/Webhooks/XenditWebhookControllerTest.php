<?php

namespace Tests\Feature\Http\Controllers\Webhooks;

use App\Models\Order;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class XenditWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private function fakePaidOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'KRS-TEST-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'supplier_product_ref' => 'FFP5',
            'cost_price' => 900,
            'reseller_cost_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
            'payment_ref' => 'pr-123',
        ], $overrides));
    }

    private function bindFakePaymentGateway(bool $validSignature, PaymentWebhookEvent $event): void
    {
        $this->app->bind(PaymentGateway::class, fn () => new class($validSignature, $event) implements PaymentGateway
        {
            public function __construct(
                private readonly bool $validSignature,
                private readonly PaymentWebhookEvent $event,
            ) {
            }

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function verifyWebhookSignature(string $providedToken): bool
            {
                return $this->validSignature;
            }

            public function parseWebhookEvent(array $payload): PaymentWebhookEvent
            {
                return $this->event;
            }
        });
    }

    private function bindFakeSupplierAdapter(): void
    {
        $this->app->bind(SupplierAdapter::class, fn () => new class implements SupplierAdapter
        {
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
                return SupplierResponse::success(['supplier_ref' => 'GV-WEBHOOK-TEST']);
            }

            public function checkStatus(string $supplierRef): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used in this test');
            }
        });
    }

    public function test_rejects_a_webhook_with_an_invalid_signature(): void
    {
        $order = $this->fakePaidOrder();
        $this->bindFakePaymentGateway(false, new PaymentWebhookEvent(
            eventType: 'payment.capture',
            referenceId: $order->order_number,
            paymentRequestId: 'pr-123',
            status: PaymentStatus::Paid,
            amountSen: 1100,
        ));

        $response = $this->postJson('/api/webhooks/xendit', [], ['x-callback-token' => 'wrong-token']);

        $response->assertUnauthorized();
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
    }

    public function test_returns_404_when_no_order_matches_the_payment_request_id(): void
    {
        $this->bindFakePaymentGateway(true, new PaymentWebhookEvent(
            eventType: 'payment.capture',
            referenceId: 'KRS-UNKNOWN',
            paymentRequestId: 'pr-does-not-exist',
            status: PaymentStatus::Paid,
            amountSen: 1100,
        ));

        $response = $this->postJson('/api/webhooks/xendit', [], ['x-callback-token' => 'correct-token']);

        $response->assertNotFound();
    }

    /**
     * The full loop this whole session's work has been building
     * toward: a verified paid webhook drives payment_status AND
     * triggers real supplier fulfillment.
     */
    public function test_marks_paid_and_triggers_fulfillment_on_a_verified_paid_event(): void
    {
        $order = $this->fakePaidOrder();
        $this->bindFakePaymentGateway(true, new PaymentWebhookEvent(
            eventType: 'payment.capture',
            referenceId: $order->order_number,
            paymentRequestId: 'pr-123',
            status: PaymentStatus::Paid,
            amountSen: 1100,
        ));
        $this->bindFakeSupplierAdapter();

        $response = $this->postJson('/api/webhooks/xendit', [], ['x-callback-token' => 'correct-token']);

        $response->assertOk();

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::Paid, $fresh->payment_status);
        $this->assertSame(DeliveryStatus::Delivered, $fresh->delivery_status);
        $this->assertSame('GV-WEBHOOK-TEST', $fresh->supplier_ref);
    }

    /**
     * PAY-2: a repeat webhook delivery for an already-paid order must
     * be acknowledged, not reprocessed (double-fulfillment risk).
     */
    public function test_acknowledges_without_reprocessing_when_already_paid(): void
    {
        $order = $this->fakePaidOrder([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
            'supplier_ref' => 'GV-ALREADY-DONE',
        ]);
        $this->bindFakePaymentGateway(true, new PaymentWebhookEvent(
            eventType: 'payment.capture',
            referenceId: $order->order_number,
            paymentRequestId: 'pr-123',
            status: PaymentStatus::Paid,
            amountSen: 1100,
        ));

        $response = $this->postJson('/api/webhooks/xendit', [], ['x-callback-token' => 'correct-token']);

        $response->assertOk();
        $this->assertSame('GV-ALREADY-DONE', $order->fresh()->supplier_ref);
    }

    public function test_marks_payment_failed_without_attempting_fulfillment_on_a_failure_event(): void
    {
        $order = $this->fakePaidOrder();
        $this->bindFakePaymentGateway(true, new PaymentWebhookEvent(
            eventType: 'payment.failure',
            referenceId: $order->order_number,
            paymentRequestId: 'pr-123',
            status: PaymentStatus::Failed,
            amountSen: 1100,
        ));

        $response = $this->postJson('/api/webhooks/xendit', [], ['x-callback-token' => 'correct-token']);

        $response->assertOk();

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::Failed, $fresh->payment_status);
        $this->assertSame(DeliveryStatus::NotStarted, $fresh->delivery_status);
    }
}
