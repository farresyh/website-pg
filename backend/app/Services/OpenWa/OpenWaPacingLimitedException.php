<?php

namespace App\Services\OpenWa;

use RuntimeException;

/**
 * ADR-116 decision 7: OpenWA's own send pacing refused this send (HTTP 429,
 * `code: SEND_PACING_LIMITED`). Not a failure: the message waits
 * `retryAfterSeconds` and goes out then. A RuntimeException subclass, so
 * callers that don't know about pacing (the reseller bot's reply job)
 * behave exactly as they did before.
 */
final class OpenWaPacingLimitedException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct("OpenWA send pacing limited, retry after {$retryAfterSeconds}s");
    }
}
