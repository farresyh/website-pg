<?php

namespace App\Services\Accounting;

/**
 * ADR-110 PR-B addendum — the three tables a CHIP settlement file's
 * `Transaction ID` can belong to: retail/affiliate checkout, self-serve
 * membership subscription, self-serve reseller wallet top-up. A raw
 * string enum + plain `matched_id`, resolved by
 * `ChipSettledTransaction::matchedRecord()` — never Eloquent's
 * `morphTo()`/`morphMap()` (this codebase has never used Eloquent's
 * polymorphic relations anywhere, same `LedgerOwnerType` idiom,
 * ADR-057/059).
 */
enum ChipTransactionMatchType: string
{
    case Order = 'order';
    case MembershipCheckoutAttempt = 'membership_checkout_attempt';
    case WalletTopupAttempt = 'wallet_topup_attempt';
}
