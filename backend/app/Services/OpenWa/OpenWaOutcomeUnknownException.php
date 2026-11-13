<?php

namespace App\Services\OpenWa;

use RuntimeException;

/**
 * ADR-116 2026-11-14 addendum, decision 4: OpenWA answered that an earlier
 * attempt with this Idempotency-Key may already have sent the message
 * (`IDEMPOTENCY_OUTCOME_UNKNOWN`), or that the key was already used for a
 * different text (`IDEMPOTENCY_KEY_REUSED`). Sending again could send it
 * twice, so the row is marked `unknown` for a person to check.
 */
final class OpenWaOutcomeUnknownException extends RuntimeException {}
