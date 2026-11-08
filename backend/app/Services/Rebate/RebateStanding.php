<?php

namespace App\Services\Rebate;

use App\Models\ResellerRebateLadder;

/** One reseller's rebate position for one month, as of an instant. Built only by RebateEntitlement. */
final readonly class RebateStanding
{
    public function __construct(
        public ?ResellerRebateLadder $ladder,
        public ?int $tierId,
        public int $salesSen,
        public int $profitSen,
        public int $reachedStep,
        public int $capSen,
        public int $paidSen,
    ) {}

    /** Cumulative ladder reward at a step for this month's tier (0 when the tier has no column). */
    public function ladderCumulative(int $step): int
    {
        return $this->ladder === null || $this->tierId === null ? 0 : ($this->ladder->cumulativeFor($this->tierId, $step) ?? 0);
    }

    /** Decision 12: min(ladder at the highest step reached, cap). */
    public function entitlementSen(): int
    {
        return $this->entitlementThrough($this->reachedStep);
    }

    /** min(ladder at $step, cap): what the month may have paid once steps 1..$step are claimed. */
    public function entitlementThrough(int $step): int
    {
        return max(0, min($this->ladderCumulative(min($step, $this->reachedStep)), $this->capSen));
    }

    public function earnsRebate(): bool
    {
        return $this->ladder !== null && $this->tierId !== null && $this->ladder->cumulativeFor($this->tierId, 1) !== null;
    }
}
