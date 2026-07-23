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
    ): LedgerEntry {
        return LedgerEntry::query()->create([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'type' => $type,
            'amount' => $amount,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * The one operation that needs real atomicity: check-then-insert must
     * be serialized per owner, or two concurrent withdrawals can both read
     * a sufficient balance before either commits (see docs/adr.md ADR-002
     * addendum). The `ledger_accounts` row is locked as a pure mutex —
     * it holds no balance data itself.
     */
    public function withdraw(
        string $ownerType,
        ?int $ownerId,
        int $amount,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $createdBy = null,
    ): LedgerEntry {
        return DB::transaction(function () use ($ownerType, $ownerId, $amount, $referenceType, $referenceId, $createdBy) {
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

            return $this->credit($ownerType, $ownerId, -$amount, 'withdrawal', $referenceType, $referenceId, $createdBy);
        });
    }
}
