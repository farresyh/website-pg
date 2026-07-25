<?php

namespace App\Services\Pricing;

use RuntimeException;

/**
 * SET-11: resolves the configured fee rate for a payment method from
 * config/checkout.php — the only place these rates come from. Callers
 * (CreateCheckoutRequest) validate `payment_method` against
 * config('checkout.payment_methods') keys before this is ever reached,
 * so a missing key here means the config itself is broken, not a bad
 * client request.
 */
final class PaymentMethodFeeResolver
{
    public function resolve(string $paymentMethod): PaymentMethodFeeConfig
    {
        $config = config("checkout.payment_methods.{$paymentMethod}");

        if ($config === null) {
            throw new RuntimeException("No fee config for payment method: {$paymentMethod}");
        }

        return new PaymentMethodFeeConfig(
            percentageRate: (float) $config['percentage_rate'],
            flatFeeSen: (int) $config['flat_fee_sen'],
        );
    }
}
