<?php

/*
 * WTH-5: withdrawals at or above this amount need a second Super Admin
 * approval (maker-checker), distinct from the requester. Belongs in a
 * real Settings UI eventually (SET-*) — env-configurable here until
 * that exists, so tuning it doesn't require a code change.
 */

return [
    'maker_checker_threshold_sen' => (int) env('WITHDRAWAL_MAKER_CHECKER_THRESHOLD_SEN', 200_000), // RM 2,000
];
