<?php

namespace App\Services\Checkout;

/**
 * CheckoutService::previewTotal()'s return shape — everything the
 * storefront needs to render a Package Price / Transaction Fee /
 * Voucher Discount / Total breakdown before payment. All amounts in
 * integer sen, same convention as CheckoutTotal/PricingBreakdown.
 */
final readonly class CheckoutTotalPreview
{
    public function __construct(
        public int $sellingPriceSen,
        public ?float $memberDiscountPercent,
        public int $voucherDiscountSen,
        public int $transactionFeeSen,
        public int $finalAmountSen,
        // ADR-118 V4: `sellingPriceSen` is before the promo; this is the RM saved.
        public int $promoDiscountSen = 0,
    ) {}
}
