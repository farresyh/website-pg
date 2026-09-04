<?php

namespace App\Services\Ledger;

/**
 * ADR-057 / ADR-059: the two ledger owner kinds. `ledger_entries` /
 * `ledger_accounts` / `withdrawals` all key tenant off a polymorphic
 * `owner_type` string + nullable `owner_id`:
 *
 *  - Platform → `('platform', null)`: every brand's `platform_profit`
 *    aggregated into the one company account, withdrawn from `/admin`
 *    (WTH-1..5). ADR-061: this now spans an arbitrary number of internal
 *    brands, not just "the platform owner".
 *  - Affiliate → `('affiliate', <affiliates.id>)`: that one brand's own
 *    margin, withdrawn from its affiliate portal (ADR-059).
 *  - ResellerWallet → `('reseller_wallet', <resellers.id>)`: a prepaid
 *    wallet `Reseller` (ADR-073) account's own spend balance — a
 *    structural mirror of `Affiliate`'s earnings account, except this one
 *    only ever debits (never earns). Opened at `Reseller` account
 *    creation (ADR-073 decision 3, ADR-072 PR-B), populated starting
 *    PR-C's `wallet_topup`/`wallet_debit`/`wallet_refund` entry types.
 *
 * The string values are the literals already persisted since ADR-002 —
 * this enum replaces the scattered `'platform'` / `'affiliate'` magic
 * strings at the money seams (`LedgerService`, `OrderFulfillmentService`,
 * `VoucherService`, `AffiliateTierFeeService`, `AffiliateEarningsService`),
 * per ADR-057's consequence note and the 2026-08-30 architecture review's
 * finding 3. `LedgerService`'s public methods still accept a raw string
 * too, so no existing caller is forced to change.
 */
enum LedgerOwnerType: string
{
    case Platform = 'platform';
    case Affiliate = 'affiliate';
    case ResellerWallet = 'reseller_wallet';

    /**
     * Normalize a value that may already be an enum or a raw string
     * (a DB read, a legacy caller) to the enum. Throws \ValueError on an
     * unknown string — a loud failure at a money seam is correct.
     */
    public static function coerce(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }
}
