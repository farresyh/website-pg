<?php

namespace App\Services\Reseller;

use RuntimeException;

/**
 * ADR-074 2026-10-08 addendum: the live wallet price is above the caller's
 * `maxPriceSen` ceiling, so nothing was charged. Channel-neutral; the
 * Reseller API maps it to `PRICE_CHANGED`.
 */
final class PriceAboveMaxException extends RuntimeException
{
    public function __construct(public readonly int $currentPriceSen, int $maxPriceSen)
    {
        parent::__construct("Live price {$currentPriceSen} sen is above max_price_sen {$maxPriceSen}.");
    }
}
