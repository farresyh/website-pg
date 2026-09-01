<?php

namespace Tests\Unit\Services\Payment;

use App\Services\Payment\Chip\ChipGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use App\Services\Payment\UnsupportedPaymentGatewayException;
use Illuminate\Http\Request;
use Tests\TestCase;

class PaymentGatewayFactoryTest extends TestCase
{
    /**
     * ADR-022's 2026-09-01 addendum — CHIP is the only gateway bound in
     * AppServiceProvider now (Xendit removed).
     */
    public function test_resolves_the_chip_gateway_bound_in_the_container(): void
    {
        $factory = $this->app->make(PaymentGatewayFactory::class);

        $gateway = $factory->make('chip');

        $this->assertInstanceOf(PaymentGateway::class, $gateway);
        $this->assertInstanceOf(ChipGateway::class, $gateway);
    }

    /**
     * 'xendit' was a real bound gateway until the 2026-09-01 addendum —
     * it must now throw like any other unknown name, never silently
     * misroute (a historical order's `payment_gateway = 'xendit'`
     * snapshot reaching reconciliation is handled there, not here).
     */
    public function test_throws_for_the_removed_xendit_gateway(): void
    {
        $factory = $this->app->make(PaymentGatewayFactory::class);

        $this->expectException(UnsupportedPaymentGatewayException::class);

        $factory->make('xendit');
    }

    public function test_throws_for_an_unbound_gateway_name(): void
    {
        $factory = $this->app->make(PaymentGatewayFactory::class);

        $this->expectException(UnsupportedPaymentGatewayException::class);

        $factory->make('stripe');
    }

    /**
     * Proves the actual point of this seam: a second gateway becomes
     * resolvable the moment it's bound in the container, with no
     * change to this factory's own code — matching the multi-gateway
     * seam decision (payment_methods.gateway, 2026-07-25).
     */
    public function test_resolves_a_newly_bound_gateway_without_code_changes(): void
    {
        $fake = new class implements PaymentGateway
        {
            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                return PaymentResponse::success([]);
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                return PaymentResponse::success([]);
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                return true;
            }

            public function parseWebhookEvent(array $payload): PaymentWebhookEvent
            {
                throw new \RuntimeException('not used in this test');
            }
        };
        $this->app->bind('payment-gateway.billplz', fn () => $fake);

        $factory = $this->app->make(PaymentGatewayFactory::class);

        $this->assertSame($fake, $factory->make('billplz'));
    }
}
