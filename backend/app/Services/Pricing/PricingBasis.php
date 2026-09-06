<?php

namespace App\Services\Pricing;

/**
 * ADR-027 Phase 6 / its 2026-08-29 continued addendum decision 5. Every
 * order defaults to Standard (the column's own DB default) — only a
 * checkout that resolved a valid, quota-sufficient membership ever
 * stamps Member. `OrderResendService` branches on this to know whether
 * to recompute profit via PricingService (Standard) or
 * MembershipPricingService (Member).
 *
 * ADR-073 decision 4: a `Reseller` (wallet) order stamps `ResellerWallet`
 * — priced via `reseller_tiers.markup_percent`, distinct from both.
 *
 * ADR-060 decision 5: a third-party affiliate storefront order priced
 * against an active wholesale tier stamps `Affiliate` — wholesale base
 * `cost x (1 + tier.markup_percent)` plus the affiliate's own margin. An
 * affiliate whose tier has lapsed falls back to `Standard` (ADR-060
 * 2026-09-05 addendum, decision 3), never its own basis.
 */
enum PricingBasis: string
{
    case Standard = 'standard';
    case Member = 'member';
    case ResellerWallet = 'reseller-wallet';
    case Affiliate = 'affiliate';
}
