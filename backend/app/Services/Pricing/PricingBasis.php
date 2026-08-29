<?php

namespace App\Services\Pricing;

/**
 * ADR-027 Phase 6 / its 2026-08-29 continued addendum decision 5. Every
 * order defaults to Standard (the column's own DB default) — only a
 * checkout that resolved a valid, quota-sufficient membership ever
 * stamps Member. `OrderResendService` branches on this to know whether
 * to recompute profit via PricingService (Standard) or
 * MembershipPricingService (Member).
 */
enum PricingBasis: string
{
    case Standard = 'standard';
    case Member = 'member';
}
