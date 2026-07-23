<?php

namespace App\Services\Pricing;

final class CheckoutTotalService
{
    public function calculate(
        int $sellingPrice,
        int $voucherDiscount,
        PaymentMethodFeeConfig $fee,
    ): CheckoutTotal {
        if ($voucherDiscount > $sellingPrice) {
            throw new InvalidPricingConfigException(
                "voucher_discount ({$voucherDiscount}) exceeds selling_price ({$sellingPrice})",
            );
        }

        $feeBase = $sellingPrice - $voucherDiscount;

        // Fractional sen round half away from zero (deliberate policy, not
        // an accident of PHP's round() default — see CheckoutTotalServiceTest).
        $transactionFee = (int) round($feeBase * $fee->percentageRate / 100) + $fee->flatFeeSen;
        $finalAmount = $feeBase + $transactionFee;

        return new CheckoutTotal(
            sellingPrice: $sellingPrice,
            voucherDiscount: $voucherDiscount,
            feeBase: $feeBase,
            transactionFee: $transactionFee,
            finalAmount: $finalAmount,
        );
    }
}
