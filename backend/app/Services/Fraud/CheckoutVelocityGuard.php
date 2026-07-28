<?php

namespace App\Services\Fraud;

use Illuminate\Support\Facades\Cache;

/**
 * ADR-007 / FRAUD-4: a stricter, distinct-from-general-API-throttling
 * velocity check aimed at card-testing/carding-shaped abuse - many
 * different player IDs/emails/phones tried from the same IP, hoping
 * one isn't blacklisted (or one card isn't declined). Only counts
 * blacklist-triggered rejections (recordHit()), not every checkout
 * request - the general throttle:10,1 on /api/checkout (ADR-014)
 * already covers ordinary request-volume abuse.
 *
 * Cache-backed (database store, ADR-014) so state is visible across
 * queue-worker/web-request processes, same reasoning as
 * App\Services\CircuitBreaker\CircuitBreaker.
 */
final class CheckoutVelocityGuard
{
    public function __construct(
        private readonly int $threshold = 3,
        private readonly int $windowMinutes = 10,
    ) {
    }

    public function tooManyRecentHits(string $ip): bool
    {
        return (int) Cache::get($this->key($ip), 0) >= $this->threshold;
    }

    public function recordHit(string $ip): void
    {
        $key = $this->key($ip);
        Cache::add($key, 0, now()->addMinutes($this->windowMinutes));
        Cache::increment($key);
    }

    private function key(string $ip): string
    {
        return "checkout_velocity:{$ip}";
    }
}
