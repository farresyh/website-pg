<?php

namespace App\Services\Pricing;

/**
 * A payment method's fee is a percentage + flat fee (SET-11). For
 * flat-only methods (CHIP's FPX / DuitNow QR), set percentageRate to
 * 0.0. Rates must come from Settings, never hardcoded in application
 * code — this value object is just the shape, not the source of truth.
 */
final readonly class PaymentMethodFeeConfig
{
    public function __construct(
        public float $percentageRate,
        public int $flatFeeSen,
    ) {}
}
