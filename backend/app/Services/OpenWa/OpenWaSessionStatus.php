<?php

namespace App\Services\OpenWa;

use Illuminate\Support\Facades\Cache;

/**
 * PR-F build addendum decision 5 — the last-known OpenWA `session.status`
 * event, cache-backed (`database` store, ADR-014) so it's visible across
 * request/queue-worker processes, same reasoning `CircuitBreaker` already
 * uses. Never polled — `OpenWaWebhookController` is the only writer, on
 * every `session.status` webhook event; `DashboardService::health()` is
 * the only reader, surfacing it as an active amber/red chip (reversed
 * from this codebase's usual "silent, same as a stuck queue worker"
 * default — this channel is a reseller's live paid ordering path).
 *
 * No TTL: an absent key means "no session.status event has ever
 * arrived" (OpenWA not yet provisioned/linked), distinct from a known
 * `disconnected` state — the Dashboard chip renders each differently.
 */
final class OpenWaSessionStatus
{
    private const CACHE_KEY = 'openwa:session_status';

    public function record(string $status): void
    {
        Cache::forever(self::CACHE_KEY, [
            'status' => $status,
            'at' => now()->toIso8601String(),
        ]);
    }

    /** @return array{status: string, at: string}|null */
    public function current(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }
}
