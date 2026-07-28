<?php

namespace App\Services\CircuitBreaker;

/**
 * DASH-2's "circuit-breaker state per supplier" - the Dashboard admin
 * screen doesn't exist yet (prd.md §15), but CircuitBreaker::state()
 * exposes this now so that screen has something real to read once
 * built, rather than needing its own retrofit.
 */
enum CircuitBreakerState: string
{
    case Closed = 'closed';
    case Open = 'open';
}
