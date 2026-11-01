<?php

namespace App\Services\Pricing;

final class CheckoutTotalService
{
    public function calculate(
        int $sellingPrice,
        int $voucherDiscount,
        PaymentMethodFeeConfig $fee,
    ): CheckoutTotal {
        if ($voucherDiscount < 0) {
            throw new InvalidPricingConfigException(
                "voucher_discount ({$voucherDiscount}) cannot be negative",
            );
        }

        if ($voucherDiscount > $sellingPrice) {
            throw new InvalidPricingConfigException(
                "voucher_discount ({$voucherDiscount}) exceeds selling_price ({$sellingPrice})",
            );
        }

        if ($fee->percentageRate >= 100) {
            throw new InvalidPricingConfigException("percentage_rate ({$fee->percentageRate}) must be below 100");
        }

        $feeBase = $sellingPrice - $voucherDiscount;
        $transactionFee = $this->grossedUpFee($feeBase, $fee);
        $finalAmount = $feeBase + $transactionFee;

        return new CheckoutTotal(
            sellingPrice: $sellingPrice,
            voucherDiscount: $voucherDiscount,
            feeBase: $feeBase,
            transactionFee: $transactionFee,
            finalAmount: $finalAmount,
        );
    }

    /**
     * ADR-024 2026-11-01 addendum: the customer pays the gateway's fee, and
     * CHIP takes its percentage of what the customer pays — fee included —
     * so the fee is grossed up rather than taken of the base (which left us
     * a sen short on many DuitNow orders). Solved, then checked against
     * CHIP's own rounding on the total and settled on CHIP's figure. Fractional
     * sen round half away from zero (deliberate policy, see the test).
     */
    private function grossedUpFee(int $feeBase, PaymentMethodFeeConfig $fee): int
    {
        $rate = $fee->percentageRate;
        $transactionFee = (int) round(($feeBase * $rate + 100 * $fee->flatFeeSen) / (100 - $rate));

        // ponytail: a rounding tie can need one more step; three is ample for any real rate.
        for ($i = 0; $i < 3; $i++) {
            $chips = (int) round(($feeBase + $transactionFee) * $rate / 100) + $fee->flatFeeSen;
            if ($chips === $transactionFee) {
                break;
            }
            $transactionFee = $chips;
        }

        return $transactionFee;
    }
}
