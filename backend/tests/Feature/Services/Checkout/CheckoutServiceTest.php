<?php

namespace Tests\Feature\Services\Checkout;

use App\Models\Order;
use App\Services\Checkout\CheckoutFailedException;
use App\Services\Checkout\CheckoutRequest;
use App\Services\Checkout\CheckoutService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderNumberService;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use App\Services\Pricing\CheckoutTotalService;
use App\Services\Pricing\PaymentMethodFeeConfig;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CheckoutServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(PaymentGateway $gateway): CheckoutService
    {
        return new CheckoutService(
            new PricingService(),
            new CheckoutTotalService(),
            new OrderNumberService(),
            $gateway,
        );
    }

    private function request(array $overrides = []): CheckoutRequest
    {
        return new CheckoutRequest(...array_merge([
            'customerEmail' => 'buyer@example.com',
            'customerPhone' => null,
            'playerId' => '123456',
            'serverId' => '1234',
            'costPriceSen' => 900,
            'resellerCostPriceSen' => 900,
            'resellerMarkupPct' => 0.0,
            'paymentFeeConfig' => new PaymentMethodFeeConfig(0.0, 100),
            'paymentMethod' => 'duitnow',
            'channelCode' => 'DUITNOW_PAY',
            'supplierProductRef' => 'FFP5',
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
            public function __construct(
                private readonly bool $success,
                private readonly ?array $data,
                private readonly ?string $errorCode,
                private readonly ?string $errorMessage,
            ) {
            }

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                return $this->success
                    ? PaymentResponse::success($this->data)
                    : PaymentResponse::failure($this->errorCode, $this->errorMessage);
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function verifyWebhookSignature(string $providedToken): bool
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

        $order = $this->service($gateway)->initiate($this->request());

        $this->assertStringStartsWith('KRS-', $order->order_number);
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
     * commit failure after a successful Xendit call could instead
     * orphan a real, payable Xendit payment link with no matching
     * Order anywhere in the system, which is worse.
     */
    public function test_initiate_keeps_the_order_when_payment_request_creation_fails(): void
    {
        $gateway = $this->fakePaymentGateway(false, null, 'API_VALIDATION_ERROR', 'bad channel_properties');

        try {
            $this->service($gateway)->initiate($this->request());
            $this->fail('Expected CheckoutFailedException was not thrown.');
        } catch (CheckoutFailedException) {
            // expected
        }

        $this->assertSame(1, Order::query()->count());
        $this->assertNull(Order::query()->first()->payment_ref);
    }
}
