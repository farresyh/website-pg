<?php

/*
 * GAME-7 (founder revision, 2026-07-25): every Package promoted from
 * Middleware gets this markup applied automatically at creation time
 * (standard_selling_price = cost_price * (1 + markup% / 100)) — admin
 * adjusts individual packages afterward in /admin/games, per-package,
 * not here. Belongs in a real Settings UI eventually (SET-*) —
 * env-configurable here until that exists.
 */

return [
    'default_markup_percent' => (float) env('PACKAGE_DEFAULT_MARKUP_PERCENT', 15),

    // ADR-015 decision #6: env-configurable so the Schedule::call()
    // entry in routes/console.php picks up the cadence from env. Live on
    // the Forge box's OS cron. ADR-077 PR-3 decision 6 dropped the
    // default 10 -> 60: supplier catalogue prices don't move on a
    // 10-minute timescale, and Price Propagation stays manually
    // triggerable from the Price Sync Center for an urgent change.
    'price_sync_interval_minutes' => (int) env('PRICE_SYNC_INTERVAL_MINUTES', 60),

    // ADR-025 decision #3: symmetric swing tolerance on a supplier's
    // incoming cost_price before PackagePriceSyncService::propagatePrice()
    // blocks the write and queues a PendingPriceChange for review — a
    // first-cut guess, not data-derived (see that ADR's Consequence to
    // track), revisit once real sync cycles produce real data.
    'price_swing_threshold_percent' => (float) env('PRICE_SWING_THRESHOLD_PERCENT', 50),

    // ADR-100 — kill-switch for PendingReactivationAutoApprover.
    // Default OFF: Pending Reactivation stays fully manual (the
    // ADR-015 decision #3 behavior this project has always had)
    // until explicitly turned on — a one-line env flip either way,
    // no deploy needed to disable it again.
    'pending_reactivation_auto_approve' => (bool) env('PENDING_REACTIVATION_AUTO_APPROVE', false),

    // ADR-100 addendum (2026-09-24) — the original flap-count gate
    // (a package deactivated more than N times in a trailing 14-day
    // window was treated as unstable and never auto-approved, however
    // long its current streak) is retired: live data showed it
    // permanently blocking exactly the popular SKUs it was meant to
    // protect (Valorant Singapore VP, confirmed active ~20h straight
    // per the founder's own Digiflazz dashboard, still stuck on a
    // rolling flap count that couldn't age out while the package kept
    // legitimately flapping). Streak length is now the only gate.

    // ADR-100 decision (raised by its 2026-09-24 addendum from 2 to 3
    // now that streak is the sole stability signal) — consecutive
    // hourly syncs a package must show 'active' before
    // PendingReactivationAutoApprover trusts the signal enough to
    // auto-approve (the non-cutoff path only — a cutoff-window match
    // auto-approves immediately, decision Q5).
    'reactivation_stability_syncs' => (int) env('REACTIVATION_STABILITY_SYNCS', 3),
];
