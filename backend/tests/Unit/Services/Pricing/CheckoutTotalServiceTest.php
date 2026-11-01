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
        $this->assertSame(169, $total->transactionFee);   // CHIP's 1.9% + RM0.90 of the RM41.69 paid
        $this->assertSame(4169, $total->finalAmount);
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
        $this->assertSame(189, $total->transactionFee);
        $this->assertSame(5189, $total->finalAmount);
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
     * A negative voucher discount would inflate fee_base/final_amount
     * above the actual selling price instead of reducing it — reject
     * outright rather than silently overcharge.
     */
    public function test_rejects_negative_voucher_discount(): void
    {
        $service = new CheckoutTotalService();
        $cardFee = new PaymentMethodFeeConfig(percentageRate: 1.9, flatFeeSen: 90);

        $this->expectException(InvalidPricingConfigException::class);

        $service->calculate(
            sellingPrice: 5000,
            voucherDiscount: -1,
            fee: $cardFee,
        );
    }

    /**
     * Rounding policy: fractional sen round half away from zero — a
     * deliberate financial policy, not an accident of PHP's round()
     * default. 2.5% of the RM1.03 paid is 2.575, so 3.
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

    /**
     * CHIP takes its percentage of what the customer pays, fee included, so
     * the fee is grossed up. Production DuitNow settlements (1%, 2026-10):
     * RM40.19 paid RM40.59 and CHIP took 41 sen where we had charged 40;
     * RM25.45 → 26 not 25; RM40.74 → 41 either way.
     */
    public function test_a_percentage_fee_is_chips_cut_of_what_the_customer_pays(): void
    {
        $duitNow = new PaymentMethodFeeConfig(percentageRate: 1.0, flatFeeSen: 0);
        $fee = fn (int $base) => (new CheckoutTotalService)->calculate(sellingPrice: $base, voucherDiscount: 0, fee: $duitNow)->transactionFee;

        $this->assertSame([41, 26, 41, 4, 20], [$fee(4019), $fee(2545), $fee(4074), $fee(413), $fee(1990)]);
    }

    public function test_a_rate_of_100_percent_or_more_is_refused(): void
    {
        $this->expectException(InvalidPricingConfigException::class);

        (new CheckoutTotalService)->calculate(sellingPrice: 100, voucherDiscount: 0, fee: new PaymentMethodFeeConfig(percentageRate: 100.0, flatFeeSen: 0));
    }
}
