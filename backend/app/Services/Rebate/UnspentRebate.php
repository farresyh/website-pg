<?php

namespace App\Services\Rebate;

use App\Models\LedgerEntry;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Reseller\WalletEntryType;

/**
 * ADR-139 decision 14: how much of a reseller's Purchase Balance is rebate
 * credit not yet spent. Derived by replaying the one wallet ledger in order,
 * never a second balance:
 *
 *   wallet_rebate +x → rebate; a debit spends rebate first, remembering the
 *   split per order; a refund gives that order's rebate part back first;
 *   wallet_rebate_forfeit removes rebate; top-ups and closure refunds are cash.
 *
 * `WalletEntryType::from()` throws on a type it does not know, so a new
 * wallet entry type fails closed here instead of mis-stating closure.
 */
final class UnspentRebate
{
    public function forReseller(int $resellerId): int
    {
        $rebate = 0;
        $takenByOrder = [];

        $entries = LedgerEntry::query()
            ->where('owner_type', LedgerOwnerType::ResellerWallet->value)
            ->where('owner_id', $resellerId)
            ->orderBy('id')
            ->cursor();

        foreach ($entries as $entry) {
            $amount = (int) $entry->amount;

            switch (WalletEntryType::from($entry->type)) {
                case WalletEntryType::Topup:
                case WalletEntryType::ClosureRefund:
                    break;
                case WalletEntryType::Rebate:
                case WalletEntryType::RebateForfeit:
                    $rebate += $amount;
                    break;
                case WalletEntryType::Debit:
                    $take = min($rebate, -$amount);
                    $rebate -= $take;
                    $takenByOrder[$entry->reference_id] = ($takenByOrder[$entry->reference_id] ?? 0) + $take;
                    break;
                case WalletEntryType::Refund:
                    $back = min($takenByOrder[$entry->reference_id] ?? 0, $amount);
                    $rebate += $back;
                    $takenByOrder[$entry->reference_id] = ($takenByOrder[$entry->reference_id] ?? 0) - $back;
                    break;
            }
        }

        return max(0, $rebate);
    }
}
