<?php

/*
 * ADR-007 / FRAUD-4: a checkout velocity guard distinct from the
 * general throttle:10,1 on /api/checkout (ADR-014). That general
 * throttle counts every request; this counts only blacklist-triggered
 * rejections from a given IP - the signal that someone is probing
 * with different player IDs/emails/phones (carding-shaped behavior),
 * not just making a lot of legitimate requests.
 */

return [
    'velocity' => [
        'threshold' => (int) env('FRAUD_VELOCITY_THRESHOLD', 3),
        'window_minutes' => (int) env('FRAUD_VELOCITY_WINDOW_MINUTES', 10),
    ],
];
