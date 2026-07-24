<?php

/*
 * VCH-6: standalone vouchers (Path A, created from the Vouchers page —
 * not the auto-computed failed-order refund, Path B) at or above this
 * amount can only be created by a Super Admin. Same env-configurable
 * pattern as config/withdrawals.php until a real Settings UI exists.
 */

return [
    'maker_checker_threshold_sen' => (int) env('VOUCHER_MAKER_CHECKER_THRESHOLD_SEN', 50_000), // RM 500
];
