<?php

namespace App\Services\OpenWa;

use RuntimeException;

/**
 * ADR-116 2026-11-14 addendum, decision 5: the gateway could not take the
 * send right now (unreachable, timed out, a 5xx, a session not ready, or
 * an earlier attempt with the same Idempotency-Key still running). Not a
 * failure: the outbox job waits and tries again with the same key, which
 * is what makes a retry after a timeout safe.
 */
final class OpenWaUnavailableException extends RuntimeException {}
