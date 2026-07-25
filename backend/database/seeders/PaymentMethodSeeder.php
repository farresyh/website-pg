<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * SET-7/SET-11: seeds the real Xendit Malaysia channel-code registry
 * (fetched live from docs.xendit.co/docs/available-payment-channels,
 * 2026-07-25 — the same static reference list referenced in
 * docs/prd.md §14's Payment Methods design-discussion note). All rows
 * start `is_active = false` and default fee rates: Xendit has no API
 * to report which channels are actually enabled for this merchant
 * account or the contracted rate, so admin must confirm each one
 * (Dashboard or "Test This Channel") and set the real rate before
 * flipping it on. FPX flat-fee default and card/e-wallet
 * percentage+flat default mirror the same figures the deleted
 * config/checkout.php stopgap used (already proven against
 * CheckoutTotalServiceTest's worked examples) — a starting point, not
 * a confirmed contracted rate for every individual channel.
 */
class PaymentMethodSeeder extends Seeder
{
    use WithoutModelEvents;

    private const FPX_BANKS = [
        'AFFIN_FPX' => 'Affin Bank',
        'AFFIN_FPX_BUSINESS' => 'Affin Bank Business',
        'AGRO_FPX' => 'Agro Bank',
        'AGRO_FPX_BUSINESS' => 'Agro Bank Business',
        'ALLIANCE_FPX' => 'Alliance Bank',
        'ALLIANCE_FPX_BUSINESS' => 'Alliance Bank Business',
        'AMBANK_FPX' => 'AmBank',
        'AMBANK_FPX_BUSINESS' => 'AmBank Business',
        'BNP_FPX_BUSINESS' => 'BNP Paribas Business',
        'BOC_FPX' => 'Bank of China',
        'BSN_FPX' => 'Bank Simpanan Nasional',
        'CIMB_FPX' => 'CIMB Bank',
        'CIMB_FPX_BUSINESS' => 'CIMB Bank Business',
        'CITIBANK_FPX_BUSINESS' => 'Citibank Business',
        'DEUTSCHE_FPX_BUSINESS' => 'Deutsche Bank Business',
        'HLB_FPX' => 'Hong Leong Bank',
        'HLB_FPX_BUSINESS' => 'Hong Leong Bank Business',
        'HSBC_FPX' => 'HSBC Bank',
        'HSBC_FPX_BUSINESS' => 'HSBC Bank Business',
        'ISLAM_FPX' => 'Bank Islam',
        'ISLAM_FPX_BUSINESS' => 'Bank Islam Business',
        'KFH_FPX' => 'Kuwait Finance House',
        'KFH_FPX_BUSINESS' => 'Kuwait Finance House Business',
        'MAYB2E_FPX' => 'Maybank2E',
        'MAYB2E_FPX_BUSINESS' => 'Maybank2E Business',
        'MAYB2U_FPX' => 'Maybank2U',
        'MUAMALAT_FPX' => 'Bank Muamalat',
        'MUAMALAT_FPX_BUSINESS' => 'Bank Muamalat Business',
        'OCBC_FPX' => 'OCBC Bank',
        'OCBC_FPX_BUSINESS' => 'OCBC Bank Business',
        'PUBLIC_FPX' => 'Public Bank',
        'PUBLIC_FPX_BUSINESS' => 'Public Bank Business',
        'RAKYAT_FPX' => 'Bank Rakyat',
        'RAKYAT_FPX_BUSINESS' => 'Bank Rakyat Business',
        'RHB_FPX' => 'RHB Bank',
        'RHB_FPX_BUSINESS' => 'RHB Bank Business',
        'SCH_FPX' => 'Standard Chartered',
        'SCH_FPX_BUSINESS' => 'Standard Chartered Business',
        'UOB_FPX' => 'UOB Bank',
        'UOB_FPX_BUSINESS' => 'UOB Bank Business',
    ];

    private const EWALLETS = [
        'GRABPAY' => 'GrabPay',
        'SHOPEEPAY' => 'ShopeePay',
        'TOUCHNGO' => "Touch 'n Go eWallet",
        'WECHATPAY' => 'WeChat Pay',
    ];

    public function run(): void
    {
        foreach (self::FPX_BANKS as $code => $label) {
            $this->upsert($code, $label, 'fpx', percentageRate: 0.0, flatFeeSen: 210);
        }

        foreach (self::EWALLETS as $code => $label) {
            $this->upsert($code, $label, 'ewallet', percentageRate: 1.9, flatFeeSen: 90);
        }

        $this->upsert('CARDS', 'Card (Visa, Mastercard, etc.)', 'card', percentageRate: 1.9, flatFeeSen: 90);

        // Fee rate genuinely unconfirmed for this one — admin must set
        // it via updateFee before activating, not a guessed default.
        $this->upsert('AMBANK_VIRTUAL_ACCOUNT', 'AmBank Virtual Account', 'virtual_account', percentageRate: 0.0, flatFeeSen: 0);
    }

    private function upsert(string $channelCode, string $label, string $category, float $percentageRate, int $flatFeeSen): void
    {
        PaymentMethod::query()->firstOrCreate(
            ['channel_code' => $channelCode],
            [
                'label' => $label,
                'category' => $category,
                'gateway' => 'xendit',
                'is_active' => false,
                'percentage_rate' => $percentageRate,
                'flat_fee_sen' => $flatFeeSen,
                'requires_issuer' => false,
            ],
        );
    }
}
