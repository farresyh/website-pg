<?php

namespace App\Services\Supplier;

use RuntimeException;

/**
 * Thrown by an adapter whose supplier has no dedicated player-validation
 * endpoint (ADR-005). Every supplier this platform has ever integrated —
 * Gamevion and Digiflazz — throws this unconditionally from
 * validatePlayer(); "validation happens implicitly at order time" (ADR-005)
 * is the normal case, not an edge case. GAME-12's proposed per-mapping
 * `supports_validation` capability-check-before-calling flag was dropped
 * 2026-09-13 (docs/prd.md §16) — it never had a real capability to gate,
 * and no caller ever checked it. Don't resurrect that pattern without a
 * supplier that actually needs it.
 */
final class ValidationNotSupportedException extends RuntimeException
{
}
