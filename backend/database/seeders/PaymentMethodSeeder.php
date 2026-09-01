<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * SET-7/SET-11: the `payment_methods` channel registry. CHIP-only since
 * ADR-022's 2026-09-01 addendum (Xendit removed) — the ~40 Xendit
 * FPX-bank / e-wallet / card / virtual-account rows the previous version
 * of this seeder maintained are gone with the gateway.
 *
 * Every row starts `is_active = false`. CHIP does expose
 * `GET /payment_methods/?brand_id=…&currency=MYR` (which returns the
 * account's real `available_payment_methods`), but activation is
 * deliberately NOT synced from it — a channel is only flipped on after
 * `app:chip-smoke-test` proves it end-to-end against the real account
 * (ADR-022 decision 5), done by an admin in `/middleware/payment-methods`.
 *
 * `channel_code` values are CHIP Collect's own enum, stored as-is and
 * passed straight into `payment_method_whitelist` (no translation table —
 * see ChipGateway). `method_key` (ADR-022 2026-08-03 addendum decision 4)
 * is each row's stable per-real-method identity for
 * PaymentMethodController::updateStatus()'s mutual-exclusivity guard; with
 * one gateway no two rows collide today, but the column stays for the day
 * a second gateway's rows exist.
 *
 * Fees (chip-in.asia/collect, ADR-022): FPX is flat, no percentage —
 * RM1 B2C / RM2 B2B1. DuitNow QR's exact CHIP rate is not published; the
 * 0.25% here is PayNet's standard DuitNow QR MDR as a starting point —
 * an admin must confirm the real contracted rate against the CHIP
 * agreement before activating this channel, same discipline the Xendit
 * rows' `AMBANK_VIRTUAL_ACCOUNT` row used for its own unconfirmed rate.
 */
class PaymentMethodSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * @var list<array{channel_code: string, label: string, category: string, method_key: string, percentage_rate: float, flat_fee_sen: int}>
     */
    private const CHANNELS = [
        [
            'channel_code' => 'fpx',
            'label' => 'Online Banking (FPX)',
            'category' => 'fpx',
            'method_key' => 'fpx_chip',
            'percentage_rate' => 0.0,
            'flat_fee_sen' => 100,
        ],
        [
            'channel_code' => 'fpx_b2b1',
            'label' => 'Online Banking (FPX Business)',
            'category' => 'fpx',
            'method_key' => 'fpx_chip_b2b1',
            'percentage_rate' => 0.0,
            'flat_fee_sen' => 200,
        ],
        [
            // ADR-022 2026-09-01 addendum decision 4 — one of the two
            // launch channels. CHIP's exact channel-code string for
            // DuitNow QR is best-guess (`duitnow_qr`) until confirmed
            // against a real `GET /payment_methods/` response via
            // app:chip-smoke-test; correct it here if the smoke test
            // shows a different value before this row is ever activated.
            'channel_code' => 'duitnow_qr',
            'label' => 'DuitNow QR',
            'category' => 'duitnow_qr',
            'method_key' => 'duitnow_qr_chip',
            'percentage_rate' => 0.25,
            'flat_fee_sen' => 0,
        ],
    ];

    public function run(): void
    {
        foreach (self::CHANNELS as $channel) {
            PaymentMethod::query()->firstOrCreate(
                ['channel_code' => $channel['channel_code']],
                [
                    'method_key' => $channel['method_key'],
                    'label' => $channel['label'],
                    'category' => $channel['category'],
                    'gateway' => 'chip',
                    'is_active' => false,
                    'percentage_rate' => $channel['percentage_rate'],
                    'flat_fee_sen' => $channel['flat_fee_sen'],
                    'requires_issuer' => false,
                ],
            );
        }
    }
}
