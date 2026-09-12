<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\LedgerAccount;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Withdrawal\WithdrawalStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reseller-family harden item B (2026-09-10 audit, docs/build-log.md):
 * `Affiliate\WithdrawalController::store()` used to run its balance
 * check and "already has an open request" check as two separate
 * un-locked reads before the `Withdrawal::create()` — two concurrent
 * requests for the same affiliate could both pass both checks before
 * either commit, producing two pending `Withdrawal` rows whose combined
 * amount exceeds the affiliate's balance.
 *
 * Mirrors `ResellerWalletTopupService::initiate()`'s fix for the
 * identical class of race: lock this affiliate's own
 * `('affiliate', id)` `ledger_accounts` row as a pure mutex (it holds no
 * balance itself — balance is always `SUM(ledger_entries.amount)`,
 * ADR-002) and run both checks + the create inside that one locked
 * transaction, so a second concurrent request can't get past the lock
 * until the first has committed (or rolled back).
 *
 * The real ledger debit still only happens at admin-approve time
 * (`Admin\WithdrawalController::approve()`, already lock-guarded and
 * concurrency-tested) — this closes the "two pending rows racing past
 * the guard" gap, not a money-loss bug.
 */
final class AffiliateWithdrawalService
{
    public function __construct(
        private readonly AffiliateEarningsService $earnings,
        private readonly LedgerService $ledger,
    ) {}

    /**
     * @param  array{amount: int, bank_name: string, bank_account_no: string, bank_account_holder: string}  $data
     */
    public function request(Affiliate $affiliate, array $data, ?int $affiliateUserId): Withdrawal
    {
        // Defensive open, same call the controller made before this
        // extraction: a affiliate can have an earnings balance purely
        // from credit() calls (order margin) with no ledger_accounts row
        // yet — credit() never creates one, and this may be its first
        // ever withdrawal request. firstOrCreate() is idempotent once
        // the row exists, which is the steady-state case for every
        // request after the first.
        $this->ledger->openAccount(LedgerOwnerType::Affiliate, $affiliate->id);

        return DB::transaction(function () use ($affiliate, $data, $affiliateUserId) {
            LedgerAccount::query()
                ->where('owner_type', LedgerOwnerType::Affiliate->value)
                ->where('owner_id', $affiliate->id)
                ->lockForUpdate()
                ->firstOrFail();

            $balance = $this->earnings->balance($affiliate);
            if ($data['amount'] > $balance) {
                throw ValidationException::withMessages([
                    'amount' => ["Amount exceeds your withdrawable balance ({$balance} sen)."],
                ]);
            }

            $hasOpenRequest = Withdrawal::query()
                ->where('owner_type', LedgerOwnerType::Affiliate->value)
                ->where('owner_id', $affiliate->id)
                ->whereIn('status', [WithdrawalStatus::Pending->value, WithdrawalStatus::Approved->value])
                ->exists();

            if ($hasOpenRequest) {
                throw ValidationException::withMessages([
                    'amount' => ['You already have a withdrawal in progress. Wait for it to complete before requesting another.'],
                ]);
            }

            return Withdrawal::query()->create([
                'owner_type' => LedgerOwnerType::Affiliate->value,
                'owner_id' => $affiliate->id,
                'amount' => $data['amount'],
                'bank_name' => $data['bank_name'],
                'bank_account_no' => $data['bank_account_no'],
                'bank_account_holder' => $data['bank_account_holder'],
                'status' => WithdrawalStatus::Pending,
                'requested_by' => null,
                'affiliate_user_id' => $affiliateUserId,
            ]);
        });
    }
}
