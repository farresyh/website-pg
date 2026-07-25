<?php

/*
 * SET-11: transaction fee rate (percentage + flat) per payment method,
 * matching Xendit's published Malaysia rates (xendit.co/en-my/pricing)
 * — Cards/e-wallets are percentage + flat, FPX/DuitNow QR are
 * flat-only (percentage_rate = 0). The exact figures here match the
 * worked examples in tests/Unit/Services/Pricing/CheckoutTotalServiceTest.php.
 * Stopgap until a real Settings UI (SET-1..11) exists — env-configurable
 * so tuning doesn't require a code change, same pattern as
 * config/withdrawals.php / config/vouchers.php.
 */

return [
    'payment_methods' => [
        'card' => [
            'percentage_rate' => (float) env('CHECKOUT_FEE_CARD_PERCENTAGE', 1.9),
            'flat_fee_sen' => (int) env('CHECKOUT_FEE_CARD_FLAT_SEN', 90),
        ],
        'fpx' => [
            'percentage_rate' => (float) env('CHECKOUT_FEE_FPX_PERCENTAGE', 0.0),
            'flat_fee_sen' => (int) env('CHECKOUT_FEE_FPX_FLAT_SEN', 210),
        ],
        'duitnow_qr' => [
            'percentage_rate' => (float) env('CHECKOUT_FEE_DUITNOW_QR_PERCENTAGE', 0.0),
            'flat_fee_sen' => (int) env('CHECKOUT_FEE_DUITNOW_QR_FLAT_SEN', 210),
        ],
        'ewallet' => [
            'percentage_rate' => (float) env('CHECKOUT_FEE_EWALLET_PERCENTAGE', 1.9),
            'flat_fee_sen' => (int) env('CHECKOUT_FEE_EWALLET_FLAT_SEN', 90),
        ],
    ],
];
