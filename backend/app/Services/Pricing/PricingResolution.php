<?php

namespace App\Services\Pricing;

/**
 * The single output of CheckoutPricingResolver — every price/profit/basis
 * value a checkout Order needs, resolved from one place instead of
 * `standard`-vs-`member` (and, from ADR-060, `affiliate-tier`-vs-
 * `affiliate-lapsed`) ternaries scattered through CheckoutService.
 *
 * All amounts are integer sen (backend/AGENTS.md — never float ringgit).
 *
 *  - `sellingPriceSen`     what the customer is charged, before voucher/fee.
 *  - `platformProfitSen`   the platform's cut (standard markup, member
 *                          margin, or wholesale-tier markup).
 *  - `affiliateProfitSen`  a third-party affiliate's own margin — 0 for
 *                          every non-affiliate basis.
 *  - `normalSellingPriceSen`  the counterfactual non-member price, set
 *                          only for the Member basis (the `orders.
 *                          normal_selling_price` column); null otherwise.
 *  - `membershipId` / `memberDiscountPercent`  set only for the Member
 *                          basis — the values stamped onto the Order and
 *                          used by the locked quota decrement.
 */
final readonly class PricingResolution
{
    public function __construct(
        public int $costPriceSen,
        public int $standardSellingPriceSen,
        public int $sellingPriceSen,
        public int $platformProfitSen,
        public int $affiliateProfitSen,
        public PricingBasis $basis,
        public ?int $normalSellingPriceSen = null,
        public ?int $membershipId = null,
        public ?float $memberDiscountPercent = null,
    ) {}
}
