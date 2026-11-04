<?php

namespace App\Services\Campaign;

use App\Services\Pricing\PricingResolution;

/** What a promo code does to one order's price (read-only; nothing is reserved). */
final readonly class PromoQuote
{
    public function __construct(
        public int $promoCodeId,
        public int $discountSen,
        public PricingResolution $pricing,
    ) {}
}
