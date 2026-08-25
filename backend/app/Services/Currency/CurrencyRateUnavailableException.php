<?php

namespace App\Services\Currency;

use RuntimeException;

/**
 * Thrown only when the live FX fetch failed AND no prior rate for
 * that pair was ever stored — the one case ADR-033's own fallback
 * design can't cover, since there's nothing to fall back to. Expected
 * to be vanishingly rare in practice (the very first fetch for a
 * brand-new currency pair, on a day the FX API happens to be down).
 */
final class CurrencyRateUnavailableException extends RuntimeException
{
}
