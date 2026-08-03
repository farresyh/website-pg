<?php

namespace Tests\Unit\Services\Payment;

use App\Services\Payment\Chip\ChipGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\UnsupportedPaymentGatewayException;
use App\Services\Payment\Xendit\XenditGateway;
use Illuminate\Http\Request;
use Tests\TestCase;

class PaymentGatewayFactoryTest extends TestCase
{
    public function test_resolves_the_xendit_gateway_bound_in_the_container(): void
    {
        $factory = $this->app->make(PaymentGatewayFactory::class);

        $gateway = $factory->make('xendit');

        $this->assertInstanceOf(PaymentGateway::class, $gateway);
        $this->assertInstanceOf(XenditGateway::class, $gateway);
    }

    /**
     * ADR-022 — confirms 'chip' resolves for real, not just 'xendit',
     * now that ChipGateway is bound in AppServiceProvider.
     */
    public function test_resolves_the_chip_gateway_bound_in_the_container(): void
    {
        $factory = $this->app->make(PaymentGatewayFactory::class);

        $gateway = $factory->make('chip');

        $this->assertInstanceOf(PaymentGateway::class, $gateway);
        $this->assertInstanceOf(ChipGateway::class, $gateway);
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
            public function createPayment(\App\Services\Payment\PaymentRequest $request): \App\Services\Payment\PaymentResponse
            {
                return \App\Services\Payment\PaymentResponse::success([]);
            }

            public function getPayment(string $paymentRequestId): \App\Services\Payment\PaymentResponse
            {
                return \App\Services\Payment\PaymentResponse::success([]);
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                return true;
            }

            public function parseWebhookEvent(array $payload): \App\Services\Payment\PaymentWebhookEvent
            {
                throw new \RuntimeException('not used in this test');
            }
        };
        $this->app->bind('payment-gateway.billplz', fn () => $fake);

        $factory = $this->app->make(PaymentGatewayFactory::class);

        $this->assertSame($fake, $factory->make('billplz'));
    }
}
