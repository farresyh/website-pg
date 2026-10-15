<?php

namespace App\Services\CustomerAnalytics;

use Carbon\CarbonImmutable;

/**
 * The Customer Analytics filters (ADR-049 2026-10-15 addendum). The date,
 * Affiliate and Reseller filters narrow which orders count toward a
 * customer's displayed figures; segment and search only pick which
 * customers the list shows, so the stat cards drop them (decision 5).
 */
final readonly class CustomerFilters
{
    public function __construct(
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $toExclusive = null,
        public ?int $affiliateId = null,
        public ?int $resellerId = null,
        public ?CustomerSegment $segment = null,
        public ?string $search = null,
    ) {}

    public function withoutListFilters(): self
    {
        return new self($this->from, $this->toExclusive, $this->affiliateId, $this->resellerId);
    }
}
