<?php

namespace App\Services\Pricing;

/**
 * The single output of OrderPricingResolver — every price/profit/basis
 * value an Order needs, resolved from one place instead of the
 * `standard`-vs-`member` (and, from ADR-060, `affiliate-tier`-vs-
 * `affiliate-lapsed`-vs-`reseller-wallet`) ternaries that were scattered
 * through CheckoutService and hand-rolled again in the wallet channel.
 *
 * All amounts are integer sen (backend/AGENTS.md — never float ringgit).
 *
 *  - `sellingPriceSen`     what the buyer is charged, before voucher/fee.
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
 *  - `wholesaleMarkupPct`  the wholesale tier markup this order was priced
 *                          against — set for the Affiliate and
 *                          ResellerWallet bases (the `orders.
 *                          wholesale_markup_pct` snapshot, ORD-9, read
 *                          back by OrderResendService); null for Standard
 *                          and Member.
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
        public ?float $wholesaleMarkupPct = null,
    ) {}
}
