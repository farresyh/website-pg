<?php

namespace App\Services\Reseller;

/**
 * ADR-056 (grilled 2026-08-30) decision 6: the reseller wholesale-tier
 * subscription state machine. A reseller in `Grace` still buys at their
 * tier's wholesale rate — grace exists precisely so a few days' lag in
 * the manual fee-collection stopgap doesn't damage a real business
 * relationship. Only `Lapsed` (or no subscription at all) drops the
 * reseller back to `standard_selling_price`.
 */
enum ResellerSubscriptionStatus: string
{
    case Active = 'active';
    case Grace = 'grace';
    case Lapsed = 'lapsed';

    /**
     * Whether a reseller in this state gets their tier's cost-anchored
     * wholesale base price (vs. falling back to the guest/standard price).
     */
    public function grantsWholesaleRate(): bool
    {
        return $this === self::Active || $this === self::Grace;
    }
}
