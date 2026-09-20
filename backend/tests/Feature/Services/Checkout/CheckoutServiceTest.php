<?php

namespace Tests\Feature\Services\Checkout;

use App\Models\Order;
use App\Services\Checkout\CheckoutFailedException;
use App\Services\Checkout\CheckoutRequest;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\DuplicateCheckoutAttemptException;
use App\Services\Ledger\LedgerService;
use App\Services\Membership\MembershipQuotaService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderFactory;
use App\Services\Order\OrderNumberService;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use App\Services\Pricing\CheckoutTotalService;
use App\Services\Pricing\MembershipPricingService;
use App\Services\Pricing\OrderPricingResolver;
use App\Services\Pricing\PaymentMethodFeeConfig;
use App\Services\Pricing\PricingService;
use App\Services\Voucher\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CheckoutServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CheckoutService
    {
        return new CheckoutService(
            new OrderPricingResolver(new PricingService, new MembershipPricingService(new PricingService)),
            new CheckoutTotalService,
            new OrderFactory(new OrderNumberService),
            new VoucherService(new LedgerService),
            new MembershipQuotaService,
        );
    }

    private function request(array $overrides = []): CheckoutRequest
    {
        return new CheckoutRequest(...array_merge([
            'customerEmail' => 'buyer@example.com',
            'customerName' => 'Buyer One',
            'customerPhone' => null,
            'playerId' => '123456',
            'serverId' => '1234',
            'costPriceSen' => 900,
            'standardSellingPriceSen' => 900,
            'packageMarkupPercent' => 0.0,
            'affiliateMarkupPct' => 0.0,
            'paymentFeeConfig' => new PaymentMethodFeeConfig(0.0, 100),
            'paymentMethod' => 'duitnow',
            'paymentGateway' => 'chip',
            'channelCode' => 'DUITNOW_PAY',
            'idempotencyKey' => (string) Str::uuid(),
            'supplierProductRef' => 'FFP5',
            'affiliateId' => $this->primaryAffiliate()->id,
        ], $overrides));
    }

    private function fakePaymentGateway(
        bool $success,
        ?array $data = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): PaymentGateway {
        return new class($success, $data, $errorCode, $errorMessage) implements PaymentGateway
        {
            public ?PaymentRequest $receivedRequest = null;

            public function __construct(
                private readonly bool $success,
                private readonly ?array $data,
                private readonly ?string $errorCode,
                private readonly ?string $errorMessage,
            ) {}

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                $this->receivedRequest = $request;

                return $this->success
                    ? PaymentResponse::success($this->data)
                    : PaymentResponse::failure($this->errorCode, $this->errorMessage);
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                throw new RuntimeException('not used in this test');
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
    }

    public function test_initiate_creates_a_pending_order_with_snapshotted_pricing(): void
    {
        $gateway = $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-123']);

        $order = $this->service()->initiate($this->request(), $gateway);

        $this->assertStringStartsWith('PG-', $order->order_number);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame(DeliveryStatus::NotStarted, $order->delivery_status);
        $this->assertNull($order->reference_number); // ORD-8: not assigned until delivery starts
        $this->assertSame(1000, $order->final_amount); // 900 selling + 100 flat fee, no markup/voucher
        $this->assertSame('pr-123', $order->payment_ref);
    }

    /**
     * A payment-gateway failure leaves the Order in place (Pending, no
     * payment_ref) rather than rolling it back — that's the safe
     * failure direction. Order creation and the gateway call are
     * deliberately not wrapped in one transaction: if they were, a
     * commit failure after a successful gateway call could instead
     * orphan a real, payable CHIP purchase link with no matching
     * Order anywhere in the system, which is worse.
     */
    public function test_initiate_keeps_the_order_when_payment_request_creation_fails(): void
    {
        $gateway = $this->fakePaymentGateway(false, null, 'API_VALIDATION_ERROR', 'bad channel_properties');

        try {
            $this->service()->initiate($this->request(), $gateway);
            $this->fail('Expected CheckoutFailedException was not thrown.');
        } catch (CheckoutFailedException) {
            // expected
        }

        $this->assertSame(1, Order::query()->count());
        $this->assertNull(Order::query()->first()->payment_ref);
    }

    /**
     * ADR-022's newest addendum, decision 1: the Order snapshots which
     * gateway/channel it checked out with so ReconcilePendingPaymentsCommand
     * can resolve the correct PaymentGateway per order once a second
     * gateway exists — never a live-follow of the payment_methods row
     * (that row is mutable admin config, the Order's own history must not
     * silently change if it's later edited).
     */
    public function test_initiate_stamps_the_payment_gateway_and_channel_code_onto_the_order(): void
    {
        $gateway = $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-123']);

        $order = $this->service()->initiate($this->request([
            'paymentGateway' => 'chip',
            'channelCode' => 'CHIP_FPX_B2C',
        ]), $gateway);

        $this->assertSame('chip', $order->payment_gateway);
        $this->assertSame('CHIP_FPX_B2C', $order->channel_code);
    }

    public function test_initiate_stamps_the_checkout_idempotency_key_onto_the_order(): void
    {
        $gateway = $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-123']);

        $order = $this->service()->initiate($this->request(['idempotencyKey' => 'idem-abc']), $gateway);

        $this->assertSame('idem-abc', $order->checkout_idempotency_key);
    }

    /**
     * ADR-019's checkout-level idempotency fix: the unique constraint
     * on checkout_idempotency_key is what actually closes the race a
     * plain app-level SELECT-then-INSERT would miss — a second
     * initiate() call with a key that's already taken must never reach
     * the gateway a second time.
     */
    public function test_initiate_throws_duplicate_checkout_attempt_when_the_key_already_exists(): void
    {
        $gateway = $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-123']);
        $this->service()->initiate($this->request(['idempotencyKey' => 'idem-dup']), $gateway);

        try {
            $this->service()->initiate($this->request(['idempotencyKey' => 'idem-dup']), $gateway);
            $this->fail('Expected DuplicateCheckoutAttemptException was not thrown.');
        } catch (DuplicateCheckoutAttemptException) {
            // expected
        }

        $this->assertSame(1, Order::query()->count());
    }

    /**
     * The resume() path CheckoutController reaches when a retried
     * request finds an Order tagged with its key but no payment_ref
     * yet (the earlier attempt's gateway call failed). Reuses the
     * Order's own already-snapshotted final_amount/customer fields —
     * never recomputes pricing, matching ORD-9.
     */
    public function test_resume_retries_payment_for_an_existing_unpaid_order_without_recomputing_pricing(): void
    {
        $failingGateway = $this->fakePaymentGateway(false, null, 'API_VALIDATION_ERROR', 'bad channel_properties');

        try {
            $this->service()->initiate($this->request(['idempotencyKey' => 'idem-resume']), $failingGateway);
            $this->fail('Expected CheckoutFailedException was not thrown.');
        } catch (CheckoutFailedException) {
            // expected
        }

        $order = Order::query()->firstOrFail();
        $this->assertNull($order->payment_ref);
        $originalFinalAmount = $order->final_amount;

        $succeedingGateway = $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-resumed']);
        $resumed = $this->service()->resume($order, $succeedingGateway, 'DUITNOW_PAY');

        $this->assertSame('pr-resumed', $resumed->payment_ref);
        $this->assertSame($originalFinalAmount, $resumed->final_amount);
        $this->assertSame(1, Order::query()->count());
    }

    /**
     * The storefront can't know order_number when it builds the checkout
     * request (the Order doesn't exist yet), so it sends a generic
     * fallback return URL. Once the Order exists, requestPayment() must
     * overwrite it with the real per-order tracking page — otherwise a
     * redirect-based channel (FPX, some e-wallets) sends the customer
     * back to the general order-lookup page instead of their own order.
     */
    public function test_initiate_overwrites_the_return_urls_with_the_orders_own_status_page(): void
    {
        $gateway = $this->fakePaymentGateway(true, ['payment_request_id' => 'pr-123']);

        $order = $this->service()->initiate($this->request([
            'channelProperties' => [
                'success_return_url' => 'http://localhost:3001/track-order',
                'failure_return_url' => 'http://localhost:3001/track-order',
            ],
        ]), $gateway);

        $expectedUrl = rtrim((string) config('services.storefront.url'), '/')
            .'/order/status/'.$order->order_number;

        $this->assertSame($expectedUrl, $gateway->receivedRequest->channelProperties['success_return_url']);
        $this->assertSame($expectedUrl, $gateway->receivedRequest->channelProperties['failure_return_url']);
    }
}
