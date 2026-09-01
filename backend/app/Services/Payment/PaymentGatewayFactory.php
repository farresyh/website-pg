<?php

namespace App\Services\Payment;

use Illuminate\Contracts\Container\Container;

/**
 * Resolves a PaymentGateway implementation by name, keyed on
 * `payment_methods.gateway` — the per-channel widening of ADR-001's
 * originally single-gateway seam (see the create_payment_methods_table
 * migration's doc comment for why). Only `'chip'` is bound today, as
 * `payment-gateway.chip` in AppServiceProvider (ADR-022's 2026-09-01
 * addendum removed Xendit); any other value throws rather than
 * silently defaulting, since that would misroute a real payment to the
 * wrong processor. The factory is kept even with one gateway because a
 * future multi-region ADR would re-add a second one — added here only
 * once it's actually researched and built against its real API, this
 * factory does not speculate on that shape (ADR-006 discipline).
 *
 * Resolves through the container (by a `payment-gateway.<name>`
 * binding key) rather than constructing adapters directly, so tests
 * can rebind a single fake per gateway and have both this factory and
 * the plain `PaymentGateway::class` default binding pick it up
 * uniformly (see ChipWebhookControllerTest / CheckoutControllerTest).
 */
final class PaymentGatewayFactory
{
    public function __construct(private readonly Container $container) {}

    public function make(string $gateway): PaymentGateway
    {
        $key = "payment-gateway.{$gateway}";

        if (! $this->container->bound($key)) {
            throw new UnsupportedPaymentGatewayException(
                "No PaymentGateway implementation for gateway: {$gateway}",
            );
        }

        return $this->container->make($key);
    }
}
