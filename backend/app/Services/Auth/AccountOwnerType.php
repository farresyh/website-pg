<?php

namespace App\Services\Auth;

/**
 * ADR-072 decision 5 / PR-G: which kind of tenant an `affiliate_users`
 * portal-login row belongs to — mirrors the `LedgerOwnerType` idiom
 * already established in this codebase (ADR-057/ADR-059) rather than
 * inventing a new "which kind of tenant" pattern, and — like
 * `LedgerOwnerType` — a raw `owner_type` string + `owner_id` int pair,
 * resolved by an explicit accessor method on the owning model, never an
 * Eloquent `morphTo()`/`morphMap()` (this codebase has never used
 * Eloquent's polymorphic relations anywhere; see `AffiliateUser`'s own
 * doc comment for the build-time judgment call this pins).
 */
enum AccountOwnerType: string
{
    case Affiliate = 'affiliate';
    case Reseller = 'reseller';

    /**
     * Normalize a value that may already be an enum or a raw string (a
     * DB read via `Model::casts()`, or a route-parameter string off
     * `EnsureAccountType`'s own middleware argument) to the enum. Throws
     * \ValueError on an unknown string — a loud failure at an auth
     * boundary is correct, same posture `LedgerOwnerType::coerce()`
     * takes at a money boundary.
     */
    public static function coerce(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }
}
