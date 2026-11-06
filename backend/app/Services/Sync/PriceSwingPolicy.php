<?php

namespace App\Services\Sync;

use App\Models\Supplier;

/**
 * ADR-138 decision 15: the swing limits, set per supplier in `/middleware`.
 * A blank or non-positive value means the default.
 */
final class PriceSwingPolicy
{
    private function __construct(
        public readonly float $holdPercent,
        public readonly int $holdHours,
        public readonly int $massLimit,
    ) {}

    public static function for(Supplier $supplier): self
    {
        $number = function (string $key, float $default) use ($supplier): float {
            $value = $supplier->api_config[$key] ?? null;

            return is_numeric($value) && (float) $value > 0 ? (float) $value : $default;
        };

        return new self(
            $number('price_drop_hold_pct', 30),
            (int) $number('price_hold_hours', 24),
            (int) $number('mass_swing_limit', 10),
        );
    }

    /** Compared in whole numbers, so exactly 30% never falls short on rounding. */
    public function isSwing(int $costSen, int $priceSen): bool
    {
        return $costSen > 0 && abs($priceSen - $costSen) * 100 >= $this->holdPercent * $costSen;
    }
}
