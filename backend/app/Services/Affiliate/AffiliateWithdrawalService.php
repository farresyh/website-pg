<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateSubscription;
use App\Models\LedgerAccount;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Withdrawal\WithdrawalStatus;
use App\Support\MalaysiaTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-132: the monthly auto-payout. The platform, not the affiliate, opens
 * the withdrawal ticket — one per affiliate per Malaysia month, for the
 * earnings balance at the 1st 00:00 MYT cut-off minus the tier fee still to
 * come. `plan()` is the one computation: `openMonthly()` acts on it and the
 * admin worklist / portal read it, so they cannot drift.
 *
 * The ledger debit still only happens at admin approve
 * (`Admin\WithdrawalController::approve()`); this opens a Pending ticket.
 *
 * Concurrency (reseller-family harden item B, 2026-09-10): the plan and the
 * create run inside one transaction holding this affiliate's own
 * `('affiliate', id)` `ledger_accounts` row as a pure mutex (balance is
 * always `SUM(ledger_entries.amount)`, ADR-002). The unique index on
 * `(owner_type, owner_id, payout_period)` is the backstop.
 */
final class AffiliateWithdrawalService
{
    /** RM10, applied to the amount after the fee reserve. */
    public const MIN_PAYOUT_SEN = 1000;

    /** The admin transfers within the 1st to 7th; a Pending ticket past it is stale. */
    public const PAYOUT_WINDOW_DAYS = 7;

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AffiliateTierFeeService $fees,
    ) {}

    /**
     * @return array{period: string, balance: int, reserve: int, fee_due_at: ?string, amount: int, skip: ?string}
     *
     * `skip` is null when a ticket would open, else one of `already_opened`,
     * `open_ticket` (an undebited Pending ticket), `no_bank`, `below_min`.
     * `balance` is what the payout is drawn from: the lower of the cut-off
     * and the current balance, so a fee charged after the cut-off or a later
     * debit can never make the ticket bigger than what is there at approve.
     */
    public function plan(Affiliate $affiliate, ?CarbonImmutable $now = null): array
    {
        $cutoff = ($now ?? CarbonImmutable::now())->setTimezone(MalaysiaTime::TIMEZONE)->startOfMonth();
        $period = $cutoff->format('Y-m');

        $balance = min(
            $this->ledger->balance(LedgerOwnerType::Affiliate, $affiliate->id, $cutoff),
            $this->ledger->balance(LedgerOwnerType::Affiliate, $affiliate->id),
        );

        $subscription = AffiliateSubscription::query()->where('affiliate_id', $affiliate->id)->first();
        $reserve = $subscription === null
            ? 0
            : $this->fees->feesDueBetween($subscription, $cutoff, $cutoff->addMonthNoOverflow());

        $amount = max(0, $balance - $reserve);

        $owned = Withdrawal::query()
            ->where('owner_type', LedgerOwnerType::Affiliate->value)
            ->where('owner_id', $affiliate->id);

        $skip = match (true) {
            (clone $owned)->where('payout_period', $period)->exists() => 'already_opened',
            (clone $owned)->where('status', WithdrawalStatus::Pending->value)->exists() => 'open_ticket',
            ! $affiliate->hasBankDetails() => 'no_bank',
            $amount < self::MIN_PAYOUT_SEN => 'below_min',
            default => null,
        };

        $fee_due_at = $reserve > 0 ? $subscription?->next_charge_at?->toIso8601String() : null;

        return compact('period', 'balance', 'reserve', 'fee_due_at', 'amount', 'skip');
    }

    public function openMonthly(Affiliate $affiliate, ?CarbonImmutable $now = null): ?Withdrawal
    {
        // A balance can exist purely from credit() calls with no
        // ledger_accounts row yet; openAccount() is idempotent.
        $this->ledger->openAccount(LedgerOwnerType::Affiliate, $affiliate->id);

        return DB::transaction(function () use ($affiliate, $now) {
            LedgerAccount::query()
                ->where('owner_type', LedgerOwnerType::Affiliate->value)
                ->where('owner_id', $affiliate->id)
                ->lockForUpdate()
                ->firstOrFail();

            $plan = $this->plan($affiliate, $now);
            if ($plan['skip'] !== null) {
                return null;
            }

            return Withdrawal::query()->create([
                'owner_type' => LedgerOwnerType::Affiliate->value,
                'owner_id' => $affiliate->id,
                'payout_period' => $plan['period'],
                'amount' => $plan['amount'],
                'bank_name' => $affiliate->bank_name,
                'bank_account_no' => $affiliate->bank_account_no,
                'bank_account_holder' => $affiliate->bank_account_holder,
                'status' => WithdrawalStatus::Pending,
                'requested_by' => null,
                'affiliate_user_id' => null,
            ]);
        });
    }

    /**
     * One affiliate failing must not stop the rest of the run.
     *
     * @return array{opened: int, failed: int}
     */
    public function openAll(?CarbonImmutable $now = null): array
    {
        $opened = 0;
        $failed = 0;

        Affiliate::query()->each(function (Affiliate $affiliate) use ($now, &$opened, &$failed) {
            try {
                $this->openMonthly($affiliate, $now) !== null && $opened++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Affiliate payout failed to open', ['affiliate_id' => $affiliate->id, 'error' => $e->getMessage()]);
            }
        });

        return compact('opened', 'failed');
    }

    /**
     * ADR-132 decision 10: what the admin needs beyond the tickets
     * themselves — affiliates with a balance who got no ticket (and why), and
     * Pending tickets left past the window. Reads `plan()`, the same
     * computation the run uses.
     *
     * @return array{period: string, skipped: list<array<string, mixed>>, stale_tickets: list<array<string, mixed>>}
     */
    public function worklist(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $month = $now->setTimezone(MalaysiaTime::TIMEZONE)->startOfMonth();
        $period = $month->format('Y-m');

        $skipped = [];
        Affiliate::query()->orderBy('id')->each(function (Affiliate $affiliate) use ($now, &$skipped) {
            $plan = $this->plan($affiliate, $now);
            if ($plan['balance'] > 0 && in_array($plan['skip'], ['no_bank', 'below_min', 'open_ticket'], true)) {
                $skipped[] = [
                    'affiliate_id' => $affiliate->id,
                    'name' => $affiliate->business_name,
                    'balance' => $plan['balance'],
                    'reserve' => $plan['reserve'],
                    'amount' => $plan['amount'],
                    'skip' => $plan['skip'],
                ];
            }
        });

        $windowEnd = $month->addDays(self::PAYOUT_WINDOW_DAYS);
        $stale = Withdrawal::query()
            ->where('owner_type', LedgerOwnerType::Affiliate->value)
            ->where('status', WithdrawalStatus::Pending->value)
            ->where(fn ($q) => $q->where('payout_period', '<', $period)
                ->when($now->gt($windowEnd), fn ($q) => $q->orWhere('payout_period', $period)))
            ->orderBy('id')
            ->get(['id', 'owner_id', 'amount', 'payout_period']);

        $names = Affiliate::withTrashed()->whereIn('id', $stale->pluck('owner_id'))->pluck('business_name', 'id');
        $stale = $stale
            ->map(fn (Withdrawal $w) => ['id' => $w->id, 'affiliate_id' => $w->owner_id, 'name' => $names[$w->owner_id] ?? null, 'amount' => $w->amount, 'payout_period' => $w->payout_period])
            ->all();

        return ['period' => $period, 'skipped' => $skipped, 'stale_tickets' => $stale];
    }
}
