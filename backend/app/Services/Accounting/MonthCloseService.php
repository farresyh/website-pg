<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriodClose;
use App\Models\BudgetEnvelope;
use App\Models\CashAccount;
use App\Services\Report\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-083 2026-10-10 addendum, decisions 9–14 — the month close, on the
 * Monthly Summary page. The only writer of `accounting_period_closes` and of
 * `profit_allocation` postings.
 *
 * A close allocates the month's operating profit exactly (decision 10), plus
 * the drift of every earlier closed month since it was closed (decision 12:
 * a past close is never rewritten; its difference is carried forward), and
 * snapshots the cash equation (decision 13). Months close in order, once
 * ended in KL time. Only the latest close can be reopened.
 */
final class MonthCloseService
{
    /** Decision 9: LWF GROUP SDN BHD's first month, by the founder's choice. */
    public const FIRST_PERIOD = '2026-09-01';

    /** Decision 13: a gap above RM1 needs a written note (it never blocks). */
    public const GAP_NOTE_THRESHOLD_SEN = 100;

    public function __construct(
        private readonly MonthlyAccountingSummaryService $summary,
        private readonly CashPositionService $position,
        private readonly BudgetEnvelopeService $envelopes,
    ) {}

    /** @return array<string, mixed> */
    public function preview(int $year, int $month): array
    {
        $close = $this->activeClose($year, $month);
        $lines = $this->summary->forPeriod($year, $month);
        $prior = $close?->prior_adjustment_sen ?? $this->priorAdjustment($year, $month);
        $allocate = $close?->allocated_sen ?? $lines['operating_profit_sen'] + $prior;
        $expenses = $this->summary->envelopeExpensesByCategory($year, $month);

        return [
            'period' => sprintf('%04d-%02d', $year, $month),
            'label' => self::label($year, $month),
            'blocked_reason' => $close === null ? $this->blockedReason($year, $month) : null,
            'operating_profit_sen' => $lines['operating_profit_sen'],
            'prior_adjustment_sen' => $prior,
            'allocate_sen' => $allocate,
            'envelope_expenses' => collect($expenses)->map(fn (int $sen, string $category) => ['category' => $category, 'label' => ExpenseCategory::from($category)->label(), 'amount_sen' => $sen])->values(),
            'net_sen' => $lines['operating_profit_sen'] - array_sum($expenses),
            'position' => $close?->equation ?? $this->position->asOf($year, $month, $allocate),
            'close' => $close === null ? null : [
                'id' => $close->id,
                'operating_profit_sen' => $close->operating_profit_sen,
                // Decision 12: how far the live figure has moved since the close; carried into the next close.
                'drift_sen' => $lines['operating_profit_sen'] - $close->operating_profit_sen,
                'cash_balances' => $close->cash_balances,
                'gap_sen' => $close->gap_sen,
                'gap_note' => $close->gap_note,
                'closed_by' => $close->closedBy?->name,
                'closed_at' => $close->created_at->toIso8601String(),
                'can_reopen' => ! AccountingPeriodClose::query()->active()->where('period_month', '>', $close->period_month)->exists(),
            ],
            'envelopes' => BudgetEnvelope::query()->where('is_active', true)->orderBy('id')->get(['id', 'name']),
            'cash_accounts' => CashAccount::query()->where('is_active', true)->orderBy('id')->get(['id', 'name']),
        ];
    }

    /**
     * @param  list<array{budget_envelope_id: int, amount_sen: int}>  $allocationLines  must sum to the amount to allocate
     * @param  list<array{cash_account_id: int, balance_sen: int}>  $cashBalances  one per active cash account
     */
    public function close(int $year, int $month, array $allocationLines, array $cashBalances, ?string $gapNote, int $adminUserId): AccountingPeriodClose
    {
        return DB::transaction(function () use ($year, $month, $allocationLines, $cashBalances, $gapNote, $adminUserId) {
            // Serialises with every envelope posting, so a double-clicked close can't pass the checks twice.
            BudgetEnvelope::query()->lockForUpdate()->get();

            if (($reason = $this->blockedReason($year, $month)) !== null) {
                $this->reject('period', $reason);
            }

            $lines = $this->summary->forPeriod($year, $month);
            $prior = $this->priorAdjustment($year, $month);
            $allocate = $lines['operating_profit_sen'] + $prior;

            $allocated = array_sum(array_map(fn (array $l) => (int) $l['amount_sen'], $allocationLines));
            if ($allocated !== $allocate) {
                $this->reject('lines', sprintf('The allocation must total exactly RM%s; these lines total RM%s.', self::rm($allocate), self::rm($allocated)));
            }

            $balances = $this->cashBalances($cashBalances);
            $position = $this->position->asOf($year, $month, $allocate);
            $cashTotal = array_sum(array_column($balances, 'balance_sen'));
            $gap = CashPositionService::gapSen($position, $cashTotal);
            $gapNote = $gapNote !== null && trim($gapNote) !== '' ? trim($gapNote) : null;

            if (abs($gap) > self::GAP_NOTE_THRESHOLD_SEN && $gapNote === null) {
                $this->reject('gap_note', sprintf('Assets and claims differ by RM%s. Write down why before closing.', self::rm($gap)));
            }

            $posting = $allocate === 0 ? null : $this->envelopes->post(
                EnvelopePostingType::ProfitAllocation,
                $allocationLines,
                Carbon::create($year, $month, 1)->endOfMonth()->toDateString(),
                'Month close — '.self::label($year, $month),
                $adminUserId,
            );

            return AccountingPeriodClose::query()->create([
                'period_month' => Carbon::create($year, $month, 1)->toDateString(),
                'operating_profit_sen' => $lines['operating_profit_sen'],
                'prior_adjustment_sen' => $prior,
                'allocated_sen' => $allocate,
                'budget_envelope_posting_id' => $posting?->id,
                'profit_lines' => $lines,
                'cash_balances' => $balances,
                'equation' => [...$position, 'cash_accounts_sen' => $cashTotal, 'gap_sen' => $gap],
                'gap_sen' => $gap,
                'gap_note' => $gapNote,
                'closed_by' => $adminUserId,
            ]);
        });
    }

    /** Decision 12: voids the latest close and its allocation; the month can then be closed again. */
    public function reopen(AccountingPeriodClose $close, string $reason, int $adminUserId): AccountingPeriodClose
    {
        return DB::transaction(function () use ($close, $reason, $adminUserId) {
            BudgetEnvelope::query()->lockForUpdate()->get();
            $locked = AccountingPeriodClose::query()->whereKey($close->id)->lockForUpdate()->firstOrFail();

            if ($locked->voided_at !== null) {
                $this->reject('close', 'This month close has already been reopened.');
            }
            if (AccountingPeriodClose::query()->active()->where('period_month', '>', $locked->period_month)->exists()) {
                $this->reject('close', 'Only the latest month close can be reopened — reopen the later month first.');
            }

            if ($locked->posting !== null) {
                $this->envelopes->void($locked->posting, "Month close reopened: {$reason}", $adminUserId, reopeningClose: true);
            }

            $locked->update(['voided_at' => now(), 'voided_by' => $adminUserId, 'void_reason' => $reason]);

            return $locked;
        });
    }

    /** Why this month can't be closed now, or null when it can. */
    private function blockedReason(int $year, int $month): ?string
    {
        $start = Carbon::create($year, $month, 1)->toDateString();

        if ($start < self::FIRST_PERIOD) {
            return 'Month closes start from '.Carbon::parse(self::FIRST_PERIOD)->format('F Y').'.';
        }
        if (Carbon::now(ReportService::TIMEZONE)->toDateString() < Carbon::create($year, $month, 1)->addMonthNoOverflow()->toDateString()) {
            return self::label($year, $month).' has not ended yet (Kuala Lumpur time).';
        }
        if ($this->activeClose($year, $month) !== null) {
            return self::label($year, $month).' is already closed.';
        }

        $previous = Carbon::create($year, $month, 1)->subMonthNoOverflow();
        if ($previous->toDateString() >= self::FIRST_PERIOD && $this->activeClose($previous->year, $previous->month) === null) {
            return 'Close '.self::label($previous->year, $previous->month).' first.';
        }

        return null;
    }

    /**
     * Decision 12: every earlier closed month's live operating profit, minus
     * what was allocated for it (its own figure plus the adjustment it
     * carried), so the allocations always add up to the live total.
     *
     * ponytail: recomputes each earlier month's summary — fine for a few
     * years of months; store a per-close live figure if this gets slow.
     */
    private function priorAdjustment(int $year, int $month): int
    {
        return (int) AccountingPeriodClose::query()
            ->active()
            ->where('period_month', '<', Carbon::create($year, $month, 1)->toDateString())
            ->get()
            ->sum(fn (AccountingPeriodClose $c) => $this->summary->forPeriod($c->period_month->year, $c->period_month->month)['operating_profit_sen'] - $c->allocated_sen);
    }

    /**
     * @param  list<array{cash_account_id: int, balance_sen: int}>  $input
     * @return list<array{cash_account_id: int, name: string, balance_sen: int}>
     */
    private function cashBalances(array $input): array
    {
        $given = collect($input)->keyBy(fn (array $b) => (int) $b['cash_account_id']);
        $accounts = CashAccount::query()->where('is_active', true)->orderBy('id')->get();

        if ($given->count() !== count($input) || $given->keys()->sort()->values()->all() !== $accounts->pluck('id')->sort()->values()->all()) {
            $this->reject('cash_balances', 'Enter one balance for every active cash account.');
        }

        return $accounts->map(fn (CashAccount $a) => [
            'cash_account_id' => $a->id,
            'name' => $a->name,
            'balance_sen' => (int) $given[$a->id]['balance_sen'],
        ])->all();
    }

    private function activeClose(int $year, int $month): ?AccountingPeriodClose
    {
        return AccountingPeriodClose::query()->active()->whereDate('period_month', Carbon::create($year, $month, 1)->toDateString())->first();
    }

    private static function label(int $year, int $month): string
    {
        return Carbon::create($year, $month, 1)->format('F Y');
    }

    private static function rm(int $sen): string
    {
        return number_format($sen / 100, 2);
    }

    private function reject(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }
}
