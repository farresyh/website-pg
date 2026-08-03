<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * SET-7/SET-11: seeds the real Xendit Malaysia channel-code registry
 * (fetched live from docs.xendit.co/docs/available-payment-channels,
 * 2026-07-25 — the same static reference list referenced in
 * docs/prd.md §14's Payment Methods design-discussion note), plus
 * CHIP's own FPX channel codes (ADR-022, `seedChip()` below). All rows
 * start `is_active = false`: neither gateway has an API to report
 * which channels are actually enabled for this merchant account, so
 * admin must confirm each one (Dashboard, "Test This Channel", or for
 * CHIP a real `app:chip-smoke-test` pass — ADR-022 decision 5) before
 * flipping it on.
 *
 * **Fee defaults corrected 2026-07-30** against Xendit's own published
 * Malaysia rate card (xendit.co/en/pricing, xendit.co/en-my/malaysia —
 * fetched live, not the deleted config/checkout.php stopgap's guessed
 * figures the previous version of this seeder carried forward). Xendit
 * itself notes actual contracted rates may differ per merchant
 * agreement/volume — this is the public list price, a much better
 * starting point than the prior placeholder, still not a substitute
 * for admin confirming the real contracted rate per channel.
 *
 * Two known gaps this correction does not (and cannot) close:
 * - **`CARDS` is one Xendit channel code covering three real rate
 *   tiers** (domestic debit 1.90%, domestic credit 2.00%,
 *   international 3.80%, all +RM0.90) — which tier applies is decided
 *   by the card presented at payment time, not selectable in advance,
 *   so this single row can only approximate one figure (kept at the
 *   debit/floor rate, 1.90%). Real fees on credit/international cards
 *   will exceed what this row reports.
 * - **`AMBANK_VIRTUAL_ACCOUNT` stays at 0%/RM0, still deliberately
 *   unconfirmed** — Xendit's published rate is 0.50% (minimum RM1.00)
 *   + RM0.90, but `percentage_rate`/`flat_fee_sen` has no minimum-floor
 *   concept, so a naive 0.50%+90sen would understate the fee on small
 *   transactions. Needs a schema decision, not a guessed number, before
 *   this channel is ever activated.
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

    /**
     * Per-channel e-wallet percentage rate — Xendit prices each
     * e-wallet differently (xendit.co/en/pricing, 2026-07-30), unlike
     * FPX where only the personal/business split matters.
     */
    private const EWALLET_RATES = [
        'GRABPAY' => 2.00,
        'TOUCHNGO' => 1.80,
        'SHOPEEPAY' => 2.50,
        'WECHATPAY' => 2.50,
    ];

    public function run(): void
    {
        foreach (self::FPX_BANKS as $code => $label) {
            // Personal RM1.20 + RM0.90 = RM2.10; Business RM2.00 + RM0.90 = RM2.90
            // (xendit.co/en/pricing, 2026-07-30).
            $flatFeeSen = str_ends_with($code, '_BUSINESS') ? 290 : 210;
            $this->upsert($code, $label, 'fpx', percentageRate: 0.0, flatFeeSen: $flatFeeSen);
        }

        foreach (self::EWALLETS as $code => $label) {
            $this->upsert($code, $label, 'ewallet', percentageRate: self::EWALLET_RATES[$code], flatFeeSen: 90);
        }

        // Domestic debit rate — see class doc comment for why credit
        // (2.00%) and international (3.80%) aren't modeled separately.
        $this->upsert('CARDS', 'Card (Visa, Mastercard, etc.)', 'card', percentageRate: 1.9, flatFeeSen: 90);

        // Fee rate genuinely unconfirmed for this one — admin must set
        // it via updateFee before activating, not a guessed default.
        $this->upsert('AMBANK_VIRTUAL_ACCOUNT', 'AmBank Virtual Account', 'virtual_account', percentageRate: 0.0, flatFeeSen: 0);

        $this->seedChip();
    }

    /**
     * ADR-022 decision 5 / newest addendum item 6 — CHIP Collect's own
     * FPX channel codes ('fpx', 'fpx_b2b1'), stored as-is (chip-in.asia
     * doesn't expose per-bank codes the way Xendit does — customer
     * picks their bank on CHIP's own hosted checkout page). Flat fee
     * only, confirmed against chip-in.asia/collect 2026-07-30: RM1
     * personal / RM2 business — an unconditional saving over Xendit's
     * own flat FPX fee (RM2.10/RM2.90), the whole reason ADR-022
     * adopted CHIP. Both start `is_active = false`: no live CHIP
     * account exists yet, and ADR-022 decision 5 explicitly forbids
     * flipping either live before `app:chip-smoke-test` passes.
     * `method_key` is each row's own value, deliberately not shared
     * with any Xendit FPX row — see PaymentMethodSeederTest's own doc
     * comment for why a 1:1 conflict doesn't apply here.
     */
    private function seedChip(): void
    {
        $this->upsertChip('fpx', 'Other Banks (FPX)', 'fpx', percentageRate: 0.0, flatFeeSen: 100, methodKey: 'fpx_chip');
        $this->upsertChip('fpx_b2b1', 'Other Banks (FPX Business)', 'fpx', percentageRate: 0.0, flatFeeSen: 200, methodKey: 'fpx_chip_b2b1');
    }

    private function upsertChip(string $channelCode, string $label, string $category, float $percentageRate, int $flatFeeSen, string $methodKey): void
    {
        PaymentMethod::query()->firstOrCreate(
            ['channel_code' => $channelCode],
            [
                'method_key' => $methodKey,
                'label' => $label,
                'category' => $category,
                'gateway' => 'chip',
                'is_active' => false,
                'percentage_rate' => $percentageRate,
                'flat_fee_sen' => $flatFeeSen,
                'requires_issuer' => false,
            ],
        );
    }

    /**
     * `method_key` defaults to the lowercased channel_code — every row
     * seeded here is Xendit-only today, so no two rows represent the
     * same real-world method yet (see the migration's own doc comment
     * and ADR-022's newest addendum, decision 4). A future CHIP row
     * representing the same real method (e.g. TnG) should pass the
     * matching existing key explicitly via $methodKey, not rely on
     * this default.
     */
    private function upsert(string $channelCode, string $label, string $category, float $percentageRate, int $flatFeeSen, ?string $methodKey = null): void
    {
        PaymentMethod::query()->firstOrCreate(
            ['channel_code' => $channelCode],
            [
                'method_key' => $methodKey ?? strtolower($channelCode),
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
