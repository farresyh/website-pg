<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-096 decision 8: cache-based (ADR-077 Redis) per-order/per-leg
 * throttle for the admin "Check from Supplier"/"Check from Gateway"
 * manual-poll buttons — no schema change, an expiry timestamp stored
 * under a plain cache key. `start()` is called after every attempt
 * (success, terminal failure, or error/timeout alike), never only on
 * success, so a supplier outage can't be hammered by rapid retries.
 */
final class ManualCheckCooldown
{
    public function remainingSeconds(string $key): int
    {
        // Stored as an ISO string, never a raw Carbon instance
        // (backend/AGENTS.md's cache-value discipline).
        $expiresAt = Cache::get($key);

        if ($expiresAt === null) {
            return 0;
        }

        return max(0, (int) now()->diffInSeconds(Carbon::parse($expiresAt), false));
    }

    public function start(string $key, int $seconds): void
    {
        Cache::put($key, now()->addSeconds($seconds)->toISOString(), $seconds);
    }
}
