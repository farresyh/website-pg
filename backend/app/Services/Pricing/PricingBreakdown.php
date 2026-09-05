<?php

namespace App\Services\Pricing;

/**
 * All amounts in integer sen (minor currency unit) — never float ringgit.
 * See docs/prd.md §8 Order/Package and docs/foundation-security.md §2.
 */
final readonly class PricingBreakdown
{
    public function __construct(
        public int $costPrice,
        public int $standardSellingPrice,
        public int $sellingPrice,
        public int $platformProfit,
        public int $affiliateProfit,
    ) {}
}
