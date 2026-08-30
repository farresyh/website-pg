<?php

namespace Tests\Feature\Http\Controllers\Webhooks;

use App\Jobs\FulfillOrderJob;
use App\Models\Order;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
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
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class XenditWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private function fakePaidOrder(array $overrides = []): Order
    {
        $supplierId = $overrides['supplier_id']
            ?? Supplier::query()->firstOrCreate(
                ['slug' => 'gamevion'],
                ['name' => 'Gamevion', 'api_config' => [], 'currency' => 'MYR'],
            )->id;

        return Order::query()->create(array_merge([
            'reseller_id' => $this->primaryReseller()->id,
            'order_number' => 'KRS-TEST-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'supplier_id' => $supplierId,
            'supplier_product_ref' => 'FFP5',
            'cost_price' => 900,
            'standard_selling_price' => 900,
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

            public function verifyWebhookSignature(Request $request): bool
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
        $this->app->bind('supplier-adapter.gamevion', fn () => new class implements SupplierAdapter
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

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
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

    public function test_rate_limits_repeated_requests_from_the_same_ip(): void
    {
        $order = $this->fakePaidOrder();
        $this->bindFakePaymentGateway(false, new PaymentWebhookEvent(
            eventType: 'payment.capture',
            referenceId: $order->order_number,
            paymentRequestId: 'pr-123',
            status: PaymentStatus::Paid,
            amountSen: 1100,
        ));

        for ($i = 0; $i < 120; $i++) {
            $this->postJson('/api/webhooks/xendit', [], ['x-callback-token' => 'wrong-token'])
                ->assertUnauthorized();
        }

        $this->postJson('/api/webhooks/xendit', [], ['x-callback-token' => 'wrong-token'])
            ->assertStatus(429);
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
     * ADR-014: fulfillment must never run inline on the webhook
     * request thread — a slow/hung Gamevion response would otherwise
     * hold the request (and Xendit's own delivery timeout) hostage.
     */
    public function test_dispatches_fulfillment_as_a_queued_job_instead_of_running_it_inline(): void
    {
        Queue::fake();

        $order = $this->fakePaidOrder();
        $this->bindFakePaymentGateway(true, new PaymentWebhookEvent(
            eventType: 'payment.capture',
            referenceId: $order->order_number,
            paymentRequestId: 'pr-123',
            status: PaymentStatus::Paid,
            amountSen: 1100,
        ));

        $response = $this->postJson('/api/webhooks/xendit', [], ['x-callback-token' => 'correct-token']);

        $response->assertOk();
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        // Nothing ran fulfillment inline — delivery is still untouched,
        // waiting for the queued job to pick it up.
        $this->assertSame(DeliveryStatus::NotStarted, $order->fresh()->delivery_status);

        Queue::assertPushed(FulfillOrderJob::class, fn (FulfillOrderJob $job) => $job->order->id === $order->id);
    }

    /**
     * The full loop this whole session's work has been building
     * toward: a verified paid webhook drives payment_status AND
     * (once the queued job runs — QUEUE_CONNECTION=sync in tests, so
     * synchronously here) triggers real supplier fulfillment.
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

    /**
     * Defense-in-depth: a signature-verified Paid event whose amount
     * doesn't match this order's own final_amount is rejected outright
     * rather than marked Paid + fulfilled.
     */
    public function test_rejects_a_paid_webhook_when_the_amount_does_not_match_the_order(): void
    {
        Queue::fake();
        $order = $this->fakePaidOrder();
        $this->bindFakePaymentGateway(true, new PaymentWebhookEvent(
            eventType: 'payment.capture',
            referenceId: $order->order_number,
            paymentRequestId: 'pr-123',
            status: PaymentStatus::Paid,
            amountSen: 1,
        ));

        $response = $this->postJson('/api/webhooks/xendit', [], ['x-callback-token' => 'correct-token']);

        $response->assertStatus(409);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        Queue::assertNotPushed(FulfillOrderJob::class);
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

    /**
     * ADR-024 decision #6a — a terminal Failed webhook is one of the
     * two real restore triggers: the customer reached the gateway page
     * (locking the voucher) but payment ultimately failed.
     */
    public function test_restores_a_reserved_voucher_redemption_on_a_failure_event(): void
    {
        $voucher = Voucher::query()->create([
            'code' => 'KRS-WEBHOOK-VOUCHER',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 300,
            'status' => 'active',
            'reason' => 'test',
        ]);
        $order = $this->fakePaidOrder(['voucher_id' => $voucher->id, 'voucher_discount' => 200]);
        VoucherRedemption::query()->create([
            'voucher_id' => $voucher->id,
            'order_id' => $order->id,
            'amount' => 200,
            'status' => 'reserved',
        ]);

        $this->bindFakePaymentGateway(true, new PaymentWebhookEvent(
            eventType: 'payment.failure',
            referenceId: $order->order_number,
            paymentRequestId: 'pr-123',
            status: PaymentStatus::Failed,
            amountSen: 1100,
        ));

        $this->postJson('/api/webhooks/xendit', [], ['x-callback-token' => 'correct-token'])->assertOk();

        $this->assertSame(500, $voucher->fresh()->remaining);
        $this->assertSame('restored', VoucherRedemption::query()->where('order_id', $order->id)->value('status'));
    }
}
