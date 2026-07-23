<?php

namespace Tests\Unit\Services\Pricing;

use App\Services\Pricing\CheckoutTotalService;
use App\Services\Pricing\InvalidPricingConfigException;
use App\Services\Pricing\PaymentMethodFeeConfig;
use PHPUnit\Framework\TestCase;

class CheckoutTotalServiceTest extends TestCase
{
    /**
     * Worked example using Xendit's real published Malaysia rate for
     * Domestic Debit cards (1.90% + MYR 0.90) confirmed via xendit.co/en-my/pricing.
     * selling_price RM50.00, voucher RM10.00 => fee_base RM40.00.
     * transaction_fee = round(4000 * 1.9%) + 90 = 76 + 90 = 166 (RM1.66).
     * final_amount = 4000 + 166 = 4166 (RM41.66).
     */
    public function test_calculates_final_amount_with_voucher_discount_applied_before_fee(): void
    {
        $service = new CheckoutTotalService();
        $cardFee = new PaymentMethodFeeConfig(percentageRate: 1.9, flatFeeSen: 90);

        $total = $service->calculate(
            sellingPrice: 5000,
            voucherDiscount: 1000,
            fee: $cardFee,
        );

        $this->assertSame(5000, $total->sellingPrice);
        $this->assertSame(1000, $total->voucherDiscount);
        $this->assertSame(4000, $total->feeBase);
        $this->assertSame(166, $total->transactionFee);
        $this->assertSame(4166, $total->finalAmount);
    }

    /**
     * No voucher (the common case) — fee_base equals selling_price.
     * transaction_fee = round(5000 * 1.9%) + 90 = 95 + 90 = 185.
     */
    public function test_calculates_final_amount_with_no_voucher(): void
    {
        $service = new CheckoutTotalService();
        $cardFee = new PaymentMethodFeeConfig(percentageRate: 1.9, flatFeeSen: 90);

        $total = $service->calculate(
            sellingPrice: 5000,
            voucherDiscount: 0,
            fee: $cardFee,
        );

        $this->assertSame(5000, $total->feeBase);
        $this->assertSame(185, $total->transactionFee);
        $this->assertSame(5185, $total->finalAmount);
    }

    /**
     * FPX/DuitNow-style fee: flat-only (percentageRate = 0), matching
     * Xendit's real published rate — MYR 1.20 + MYR 0.90 processing = 210 sen.
     */
    public function test_calculates_flat_only_fee_for_fpx_style_payment_method(): void
    {
        $service = new CheckoutTotalService();
        $fpxFee = new PaymentMethodFeeConfig(percentageRate: 0.0, flatFeeSen: 210);

        $total = $service->calculate(
            sellingPrice: 5000,
            voucherDiscount: 0,
            fee: $fpxFee,
        );

        $this->assertSame(210, $total->transactionFee);
        $this->assertSame(5210, $total->finalAmount);
    }

    /**
     * Business invariant: a voucher can never discount more than the
     * selling price itself — this would make fee_base negative, which is
     * nonsensical (never a valid state, always a bug or tampered request).
     */
    public function test_rejects_voucher_discount_exceeding_selling_price(): void
    {
        $service = new CheckoutTotalService();
        $cardFee = new PaymentMethodFeeConfig(percentageRate: 1.9, flatFeeSen: 90);

        $this->expectException(InvalidPricingConfigException::class);

        $service->calculate(
            sellingPrice: 5000,
            voucherDiscount: 6000,
            fee: $cardFee,
        );
    }

    /**
     * Rounding policy: fractional sen round half away from zero (i.e. 2.5
     * rounds to 3, not 2) — this is a deliberate financial policy, not an
     * accident of PHP's round() default. fee_base 100 * 2.5% = 2.5 exactly.
     */
    public function test_rounds_fractional_sen_half_away_from_zero(): void
    {
        $service = new CheckoutTotalService();
        $fee = new PaymentMethodFeeConfig(percentageRate: 2.5, flatFeeSen: 0);

        $total = $service->calculate(
            sellingPrice: 100,
            voucherDiscount: 0,
            fee: $fee,
        );

        $this->assertSame(3, $total->transactionFee);
    }
}
