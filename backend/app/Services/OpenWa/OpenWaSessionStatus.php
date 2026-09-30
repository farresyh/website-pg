<?php

namespace App\Services\OpenWa;

use Illuminate\Support\Facades\Cache;

/**
 * PR-F build addendum decision 5 — the last-known OpenWA `session.status`
 * event, cache-backed (default store — `redis` since ADR-077) so it's
 * visible across request/queue-worker processes, same reasoning
 * `CircuitBreaker` already uses. Never polled —
 * `OpenWaWebhookController` is the only writer, on every `session.status`
 * webhook event; `DashboardService::health()` is the only reader,
 * surfacing it as an active amber/red chip (reversed from this
 * codebase's usual "silent, same as a stuck queue worker" default —
 * this channel is a reseller's live paid ordering path).
 *
 * An absent key means "no recent `session.status` event" (OpenWA not yet
 * provisioned/linked, or nothing heard for an hour) — the Dashboard chip
 * renders this distinctly from a known `disconnected` state.
 *
 * ADR-077 decision 2: the write carries a 1-hour TTL rather than
 * `Cache::forever()`. Redis runs `maxmemory-policy volatile-lru` — only
 * keys with a TTL are evictable — so a `forever` key would sit
 * permanently un-reclaimable and, once memory filled, force Redis to
 * OOM-reject new cache writes instead of evicting. The trade accepted in
 * the ADR: if OpenWA sends no `session.status` for a full hour the chip
 * reverts to the "no recent event" rendering until the next event; a
 * genuine state change re-writes the key immediately.
 *
 * ADR-116 decision 11: tracked per session. There are two now, and one
 * shared key would let the customer-support session's status overwrite the
 * reseller bot's. The bot keeps the original key.
 */
final class OpenWaSessionStatus
{
    public const RESELLER_BOT = 'reseller-bot';

    public const CUSTOMER_SUPPORT = 'customer-support';

    private const CACHE_KEY = 'openwa:session_status';

    private const TTL_SECONDS = 3600;

    public function record(string $status, string $session = self::RESELLER_BOT): void
    {
        Cache::put($this->key($session), [
            'status' => $status,
            'at' => now()->toIso8601String(),
        ], self::TTL_SECONDS);
    }

    /** @return array{status: string, at: string}|null */
    public function current(string $session = self::RESELLER_BOT): ?array
    {
        return Cache::get($this->key($session));
    }

    private function key(string $session): string
    {
        return $session === self::RESELLER_BOT ? self::CACHE_KEY : self::CACHE_KEY.':'.$session;
    }
}
