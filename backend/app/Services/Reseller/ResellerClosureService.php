<?php

namespace App\Services\Reseller;

use App\Models\LedgerAccount;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerApiKey;
use App\Models\ResellerWebhook;
use App\Models\WalletTopupAttempt;
use App\Models\WhatsAppGroup;
use App\Services\Accounting\AccountingPeriod;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * ADR-131 decisions 12-16: the ONE way a Reseller is removed.
 *
 * The admin sends the refund first (a bank transfer to the account the money
 * came from, outside the system), then calls `close()`, which in one
 * transaction: locks the wallet account, refuses while a claim is open,
 * debits the whole balance (`wallet_closure_refund`), deactivates and
 * soft-deletes the reseller, and revokes every key, login, group and
 * webhook. A closed account is never credited again: the settlement seam
 * (`OrderSettlementService::settle()`) refuses it.
 *
 * The debit is dated today, so the transfer date must be a day of this
 * Kuala Lumpur month: a transfer in one month and a debit in the next
 * would show a false surplus in the first month's cash equation.
 * `ponytail:` a refund sent on the last day of a month cannot be closed in the
 * next: close it before midnight. Add a dated ledger write if that ever bites.
 */
final class ResellerClosureService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * What still stands between this reseller and a closure, as sentences.
     *
     * @return list<string>
     */
    public function blockers(Reseller $reseller): array
    {
        $blockers = [];

        // Not closed = not delivered and not already compensated: still with the supplier,
        // or Failed / PartiallyDelivered with no refund yet (the reseller is still owed it).
        $open = Order::query()->where('wallet_reseller_id', $reseller->id)->whereNot(fn ($q) => $q->closed())->count();
        if ($open > 0) {
            $blockers[] = "{$open} order(s) are not finished. Wait for delivery, or refund a failed order to the balance first.";
        }

        // Any pending attempt, whatever its expires_at: a payment made just before expiry can still
        // reach us late and would credit a closed account.
        $pending = WalletTopupAttempt::query()
            ->where('reseller_id', $reseller->id)
            ->where('status', WalletTopupAttemptStatus::Pending->value)
            ->count();
        if ($pending > 0) {
            $blockers[] = "{$pending} Add Balance payment(s) are still pending. Wait until they are paid, failed or expired.";
        }

        return $blockers;
    }

    /** The refunded amount (0 for an empty balance). */
    public function close(Reseller $reseller, int $adminUserId, string $transferredOn, string $bankReference): int
    {
        $this->assertTransferDate($transferredOn);

        $refunded = DB::transaction(function () use ($reseller, $adminUserId, $transferredOn, $bankReference) {
            // The mutex first: an order or a payment either commits before this and is then seen
            // below, or waits and finds an empty balance.
            LedgerAccount::query()
                ->where('owner_type', LedgerOwnerType::ResellerWallet->value)
                ->where('owner_id', $reseller->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked = Reseller::query()->lockForUpdate()->find($reseller->id);
            if ($locked === null) {
                throw ValidationException::withMessages(['reseller' => ['This account is already closed.']]);
            }

            $blockers = $this->blockers($locked);
            if ($blockers !== []) {
                throw ValidationException::withMessages(['reseller' => $blockers]);
            }

            $balance = $this->ledger->balance(LedgerOwnerType::ResellerWallet, $locked->id);
            if ($balance > 0) {
                $this->ledger->debit(
                    LedgerOwnerType::ResellerWallet,
                    $locked->id,
                    $balance,
                    'wallet_closure_refund',
                    'reseller',
                    $locked->id,
                    $adminUserId,
                    'Account closed. Refund sent '.$transferredOn.', bank ref '.$bankReference.'.',
                );
            }

            ResellerApiKey::query()->where('reseller_id', $locked->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            foreach ($locked->users()->get() as $user) {
                $user->tokens()->delete();
                $user->update(['is_active' => false]);
            }
            WhatsAppGroup::query()->where('owner_type', WhatsAppGroup::OWNER_RESELLER)->where('owner_id', $locked->id)->update(['is_active' => false]);
            ResellerWebhook::query()->where('reseller_id', $locked->id)->update(['is_active' => false]);

            $locked->update(['is_active' => false]);
            $locked->delete();

            return $balance;
        });

        Log::info('Reseller account closed', [
            'reseller_id' => $reseller->id,
            'refunded_sen' => $refunded,
            'transferred_on' => $transferredOn,
            'admin_user_id' => $adminUserId,
        ]);

        return $refunded;
    }

    /** A day of this Kuala Lumpur month, not in the future, in a month that is still open. */
    private function assertTransferDate(string $transferredOn): void
    {
        $today = now('Asia/Kuala_Lumpur');
        $day = Carbon::parse($transferredOn, 'Asia/Kuala_Lumpur');

        if ($day->toDateString() > $today->toDateString() || $day->format('Y-m') !== $today->format('Y-m')) {
            throw ValidationException::withMessages(['transferred_on' => ['The transfer date must be a day this month, not in the future. Close the account on the day you send the refund.']]);
        }

        AccountingPeriod::assertOpen($transferredOn, 'transferred_on');
        AccountingPeriod::assertOpen($today, 'transferred_on');
    }
}
