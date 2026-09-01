<?php

namespace App\Services\CustomerAnalytics;

/**
 * ANL-2 (docs/prd.md §6.12). Evaluation order in
 * CustomerAnalyticsService::classify() is most-significant-first per
 * ADR-049 decision 4 — a customer matching more than one rule shows
 * only the first that matches: Vip > Frequent > Dormant > NewCustomer >
 * OneTime. A customer matching none of these has no segment (null),
 * not a sixth case here.
 */
enum CustomerSegment: string
{
    case Vip = 'vip';
    case Frequent = 'frequent';
    case Dormant = 'dormant';
    case NewCustomer = 'new';
    case OneTime = 'one_time';

    public function label(): string
    {
        return match ($this) {
            self::Vip => 'VIP',
            self::Frequent => 'Frequent',
            self::Dormant => 'Dormant',
            self::NewCustomer => 'New',
            self::OneTime => 'One-time',
        };
    }
}
