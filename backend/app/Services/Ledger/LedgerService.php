<?php

namespace App\Services\Ledger;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;

final class LedgerService
{
    public function openAccount(string $ownerType, ?int $ownerId): LedgerAccount
    {
        return LedgerAccount::query()->firstOrCreate([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
        ]);
    }

    public function balance(string $ownerType, ?int $ownerId): int
    {
        return (int) LedgerEntry::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->sum('amount');
    }

    /**
     * Batched balance lookup for a list of same-type owners — one grouped
     * query instead of one `balance()` call per owner (ADR-058 58b's
     * reseller table renders an earnings balance per row). Owners with no
     * ledger entries are returned as `0`, so the result always has a key
     * for every id passed in.
     *
     * @param  list<int>  $ownerIds
     * @return array<int, int>
     */
    public function balances(string $ownerType, array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }

        $sums = LedgerEntry::query()
            ->where('owner_type', $ownerType)
            ->whereIn('owner_id', $ownerIds)
            ->groupBy('owner_id')
            ->selectRaw('owner_id, SUM(amount) as total')
            ->pluck('total', 'owner_id');

        $out = [];
        foreach ($ownerIds as $id) {
            $out[$id] = (int) ($sums[$id] ?? 0);
        }

        return $out;
    }

    /**
     * Additions are always safe — no "insufficient" failure mode, so no
     * locking is needed here (unlike withdraw()).
     */
    public function credit(
        string $ownerType,
        ?int $ownerId,
        int $amount,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $createdBy = null,
        ?string $reason = null,
    ): LedgerEntry {
        return LedgerEntry::query()->create([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'type' => $type,
            'amount' => $amount,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_by' => $createdBy,
            'reason' => $reason,
        ]);
    }

    /**
     * The one operation that needs real atomicity: check-then-insert must
     * be serialized per owner, or two concurrent debits can both read a
     * sufficient balance before either commits (see docs/adr.md ADR-002
     * addendum). The `ledger_accounts` row is locked as a pure mutex — it
     * holds no balance data itself. Throws InsufficientBalanceException
     * (the caller decides whether that is fatal — a withdrawal rejects it,
     * a reseller tier-fee charge starts a grace period instead, ADR-056).
     *
     * `$type` is the ledger entry type recorded for the debit
     * ('withdrawal', 'reseller_tier_fee', …). The stored `amount` is
     * negative.
     */
    public function debit(
        string $ownerType,
        ?int $ownerId,
        int $amount,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $createdBy = null,
        ?string $reason = null,
    ): LedgerEntry {
        return DB::transaction(function () use ($ownerType, $ownerId, $amount, $type, $referenceType, $referenceId, $createdBy, $reason) {
            LedgerAccount::query()
                ->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId)
                ->lockForUpdate()
                ->firstOrFail();

            $currentBalance = $this->balance($ownerType, $ownerId);

            if ($currentBalance < $amount) {
                throw new InsufficientBalanceException(
                    "Insufficient balance for {$ownerType}#{$ownerId}: has {$currentBalance}, requested {$amount}",
                );
            }

            return $this->credit($ownerType, $ownerId, -$amount, $type, $referenceType, $referenceId, $createdBy, $reason);
        });
    }

    /**
     * A payout debit — `type = 'withdrawal'`. Thin wrapper over debit()
     * kept as the named entry point every existing caller (WithdrawalController,
     * the reseller/platform-owner payout flow) already uses.
     */
    public function withdraw(
        string $ownerType,
        ?int $ownerId,
        int $amount,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $createdBy = null,
    ): LedgerEntry {
        return $this->debit($ownerType, $ownerId, $amount, 'withdrawal', $referenceType, $referenceId, $createdBy);
    }
}
