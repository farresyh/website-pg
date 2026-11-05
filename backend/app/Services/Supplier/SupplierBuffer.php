<?php

namespace App\Services\Supplier;

use Carbon\CarbonImmutable;

/**
 * ADR-115 2026-11-05 addendum: one supplier's buffer at a moment. Amounts
 * are in the supplier's own currency; the MYR figures are at the landed
 * rate (decision 9), null for a supplier with no rate yet.
 */
final readonly class SupplierBuffer
{
    public function __construct(
        public float $balance,
        public ?float $floor,
        public float $forecast,
        public float $marginPct,
        public float $required,
        public int $windowDays,
        public CarbonImmutable $coversUntil,
        public ?CarbonImmutable $busiestFrom,
        public ?CarbonImmutable $busiestTo,
        public ?float $balanceMyr,
        public ?float $requiredMyr,
    ) {}

    public function isShort(): bool
    {
        return $this->balance < $this->required;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'balance' => $this->balance,
            'floor' => $this->floor,
            'forecast' => $this->forecast,
            'margin_pct' => $this->marginPct,
            'required' => $this->required,
            'window_days' => $this->windowDays,
            'covers_until' => $this->coversUntil->toDateString(),
            'busiest_from' => $this->busiestFrom?->toDateString(),
            'busiest_to' => $this->busiestTo?->toDateString(),
            'balance_myr' => $this->balanceMyr,
            'required_myr' => $this->requiredMyr,
            'is_short' => $this->isShort(),
        ];
    }
}
