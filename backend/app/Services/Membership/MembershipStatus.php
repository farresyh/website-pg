<?php

namespace App\Services\Membership;

/**
 * ADR-027 decision 13 / its 2026-08-29 addendum decision 24: a lapsed
 * member's row flips to Expired rather than being deleted, so
 * order-history linkage (decision 3, matched by email) survives and
 * the /membership dashboard can still show past status. Renewing an
 * Expired membership updates the same row back to Active — no new row.
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
}
