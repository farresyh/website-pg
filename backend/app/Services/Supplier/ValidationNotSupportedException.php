<?php

namespace App\Services\Supplier;

use RuntimeException;

/**
 * Thrown by an adapter whose supplier has no dedicated player-validation
 * endpoint (ADR-005). Callers must check the game-supplier mapping's
 * `supports_validation` flag (GAME-12) before calling validatePlayer()
 * — this exception is the adapter-level safety net, not the primary
 * capability check.
 */
final class ValidationNotSupportedException extends RuntimeException
{
}
