<?php

namespace App\Services\Pricing;

/**
 * All amounts in integer sen. See PricingBreakdown for the same convention.
 */
final readonly class CheckoutTotal
{
    public function __construct(
        public int $sellingPrice,
        public int $voucherDiscount,
        public int $feeBase,
        public int $transactionFee,
        public int $finalAmount,
    ) {
    }
}
