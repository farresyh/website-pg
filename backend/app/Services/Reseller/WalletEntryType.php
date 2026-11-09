<?php

namespace App\Services\Reseller;

/**
 * ADR-139 stress test F5: every ledger row type a ResellerWallet account
 * can hold. Every writer takes its type from here, so the unspent-rebate
 * replay (UnspentRebate, PR 3b) can match on it exhaustively and a new
 * type cannot silently break account closure.
 */
enum WalletEntryType: string
{
    case Topup = 'wallet_topup';
    case Debit = 'wallet_debit';
    case Refund = 'wallet_refund';
    case ClosureRefund = 'wallet_closure_refund';
    case Rebate = 'wallet_rebate';
    case RebateForfeit = 'wallet_rebate_forfeit';
}
