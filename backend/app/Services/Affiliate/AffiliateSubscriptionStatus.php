<?php

namespace App\Services\Affiliate;

/**
 * ADR-056 (grilled 2026-08-30) decision 6: the affiliate wholesale-tier
 * subscription state machine. A affiliate in `Grace` still buys at their
 * tier's wholesale rate — grace exists precisely so a few days' lag in
 * the manual fee-collection stopgap doesn't damage a real business
 * relationship. Only `Lapsed` (or no subscription at all) drops the
 * affiliate back to `standard_selling_price`.
 */
enum AffiliateSubscriptionStatus: string
{
    case Active = 'active';
    case Grace = 'grace';
    case Lapsed = 'lapsed';

    /**
     * Whether an affiliate in this state gets their tier's cost-anchored
     * wholesale base price (vs. falling back to the guest/standard price).
     */
    public function grantsWholesaleRate(): bool
    {
        return $this === self::Active || $this === self::Grace;
    }
}
