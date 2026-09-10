<?php

namespace App\Services\Reseller;

use RuntimeException;

/**
 * ADR-084 PR-1 decision 5: an existing order was found for this
 * idempotency key, but the caller's payload hash does not match the one
 * stored when it was first placed — a reused key for a genuinely
 * different order. Channel-neutral (thrown by
 * `ResellerOrderPlacementService`); the Reseller API controller maps it
 * to a 409 `IDEMPOTENCY_KEY_CONFLICT`. Never reached by the Bot channel,
 * which passes no payload hash.
 */
final class IdempotencyKeyPayloadMismatchException extends RuntimeException {}
