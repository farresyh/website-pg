<?php

/*
 * GAME-7 (founder revision, 2026-07-25): every Package promoted from
 * Middleware gets this markup applied automatically at creation time
 * (reseller_cost_price = cost_price * (1 + markup% / 100)) — admin
 * adjusts individual packages afterward in /admin/games, per-package,
 * not here. Belongs in a real Settings UI eventually (SET-*) —
 * env-configurable here until that exists.
 */

return [
    'default_markup_percent' => (float) env('PACKAGE_DEFAULT_MARKUP_PERCENT', 15),
];
