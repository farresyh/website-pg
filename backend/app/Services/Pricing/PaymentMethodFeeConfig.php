<?php

namespace App\Services\Pricing;

/**
 * Xendit charges a percentage + flat fee per payment method (SET-11).
 * For flat-only methods (FPX/DuitNow), set percentageRate to 0.0.
 * Rates must come from Settings, never hardcoded in application code —
 * this value object is just the shape, not the source of truth.
 */
final readonly class PaymentMethodFeeConfig
{
    public function __construct(
        public float $percentageRate,
        public int $flatFeeSen,
    ) {
    }
}
