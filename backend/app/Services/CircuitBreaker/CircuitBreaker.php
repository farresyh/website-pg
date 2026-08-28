<?php

namespace App\Services\CircuitBreaker;

use Illuminate\Support\Facades\Cache;

/**
 * ADR-019 addendum: distinct from TransientFailureRetryPolicy, which
 * retries *within* one request. This trips *across* requests after
 * repeated failures and stops attempting calls to a down supplier for
 * a cooldown window, so a sustained outage can't turn every queued
 * order into its own multi-second timeout-and-retry cycle
 * (foundation-security.md §6, DASH-2).
 *
 * State lives in the shared `database` cache store (ADR-014) - this
 * has to be visible across queue-worker processes, not per-process
 * memory. Deliberately simplified vs. a textbook three-state breaker:
 * once the cooldown elapses, isOpen() goes false again and *every*
 * concurrent caller (not just one probe) is allowed to attempt a real
 * call - acceptable here since this system's queue workers process
 * orders per-queue, not at web-scale concurrency (ADR-020 decision
 * #5), and a genuine half-open single-probe gate isn't worth the
 * extra locking complexity for that profile.
 */
final class CircuitBreaker
{
    public function __construct(
        private readonly string $name,
        private readonly int $failureThreshold = 3,
        private readonly int $cooldownSeconds = 60,
    ) {
    }

    /**
     * ADR-051 — the breaker's own name already *is* the supplier slug
     * every 'supplier-adapter.<slug>' binding constructs it with, so
     * this is the one place CircuitBreakingSupplierAdapter can get
     * that slug for a skipped-call log row without threading it
     * through separately.
     */
    public function name(): string
    {
        return $this->name;
    }

    public function isOpen(): bool
    {
        $openedAt = Cache::get($this->openedAtKey());

        if ($openedAt === null) {
            return false;
        }

        return now()->timestamp < $openedAt + $this->cooldownSeconds;
    }

    public function state(): CircuitBreakerState
    {
        return $this->isOpen() ? CircuitBreakerState::Open : CircuitBreakerState::Closed;
    }

    public function recordSuccess(): void
    {
        Cache::forget($this->failuresKey());
        Cache::forget($this->openedAtKey());
    }

    /**
     * Trips (or re-trips, if this failure came from a post-cooldown
     * trial call) the breaker once failureThreshold is reached.
     */
    public function recordFailure(): void
    {
        // Ensures the key exists before increment() - the database
        // cache driver's increment isn't a true atomic op, but a lost
        // increment here only delays tripping by one failure, never
        // causes a wrong money/delivery outcome, so this doesn't need
        // the same row-lock discipline as LedgerService/VoucherService.
        Cache::add($this->failuresKey(), 0, $this->cooldownSeconds * 2);
        $failures = Cache::increment($this->failuresKey());

        if ($failures >= $this->failureThreshold) {
            Cache::put($this->openedAtKey(), now()->timestamp, $this->cooldownSeconds * 2);
        }
    }

    private function failuresKey(): string
    {
        return "circuit_breaker:{$this->name}:failures";
    }

    private function openedAtKey(): string
    {
        return "circuit_breaker:{$this->name}:opened_at";
    }
}
