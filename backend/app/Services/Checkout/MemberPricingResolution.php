<?php

namespace App\Services\Checkout;

/**
 * ADR-027 Phase 6. `CheckoutService::resolveMemberPricing()`'s return
 * shape — null means "price this order the standard way" (no valid
 * membership, or quota insufficient; the confirmed 2026-08-29 fallback,
 * never a blocked checkout). Non-null means member pricing applies;
 * the actual quota decrement is a separate, locked step at the same
 * commit points `VoucherService::redeem()` already uses, not here —
 * this is only the unlocked read that decided which price to charge.
 */
final class MemberPricingResolution
{
    public function __construct(
        public readonly int $membershipId,
        public readonly int $memberPriceSen,
        public readonly float $discountPercent,
    ) {
    }
}
