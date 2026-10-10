<?php

namespace App\Services\Accounting;

use App\Models\BudgetEnvelope;
use App\Models\BudgetEnvelopeEntry;
use App\Models\BudgetEnvelopePosting;
use App\Services\Report\ReportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * ADR-083 Envelope Ledger, reshaped by the 2026-10-10 addendum (decisions
 * 2–8): the sole writer of `budget_envelope_postings` and their lines.
 * `post()` records one action and enforces its type's invariants;
 * `void()` is the only correction path (a reversal posting, never an edit).
 * An envelope is an allocation, never a location (decision 1): supplier
 * top-ups and CHIP payouts never come through here.
 */
final class BudgetEnvelopeService
{
    /**
     * @param  list<array{budget_envelope_id: int, amount_sen: int}>  $lines  signed — positive into the envelope, negative out
     */
    public function post(
        EnvelopePostingType $type,
        array $lines,
        string $transactionDate,
        string $description,
        int $adminUserId,
        ?PaidFrom $counterparty = null,
        ?FundType $fundType = null,
        ?ExpenseCategory $expenseCategory = null,
        ?string $referenceNo = null,
        ?UploadedFile $receipt = null,
    ): BudgetEnvelopePosting {
        $lines = array_map(fn (array $l) => ['budget_envelope_id' => (int) $l['budget_envelope_id'], 'amount_sen' => (int) $l['amount_sen']], $lines);
        $this->assertHeader($type, $counterparty, $fundType, $expenseCategory);
        $this->assertLines($type, $lines);

        $receiptPath = null;

        try {
            return DB::transaction(function () use ($type, $lines, $transactionDate, $description, $adminUserId, $counterparty, $fundType, $expenseCategory, $referenceNo, $receipt, &$receiptPath) {
                $envelopes = $this->lockEnvelopes();

                foreach ($lines as $line) {
                    if (! ($envelopes->get($line['budget_envelope_id'])?->is_active ?? false)) {
                        $this->reject('lines', 'An archived envelope cannot take a new posting — reactivate it first.');
                    }
                }

                $amount = $this->headerAmount($type, $lines);

                if ($type === EnvelopePostingType::Repayment) {
                    $owed = $this->loanBalanceSen($counterparty);
                    if ($amount > $owed) {
                        $this->reject('lines', sprintf('This repays RM%s, but the company only owes %s RM%s.', number_format($amount / 100, 2), $counterparty->label(), number_format($owed / 100, 2)));
                    }
                }

                $receiptPath = $receipt?->store('accounting/budget-envelopes', config('filesystems.accounting_disk'));

                return $this->write($type, $amount, $lines, $transactionDate, $description, $adminUserId, $counterparty, $fundType, $expenseCategory, $referenceNo, $receiptPath);
            });
        } catch (\Throwable $e) {
            // The write rolled back — don't leave an orphan receipt behind. A storage error here must not replace $e.
            if ($receiptPath !== null) {
                rescue(fn () => Storage::disk(config('filesystems.accounting_disk'))->delete($receiptPath));
            }

            throw $e;
        }
    }

    /**
     * Posts the exact negation of every line, dated with the posting it
     * cancels so any date filter nets the pair to zero. The original stays,
     * untouched. A posting is reversed at most once (also a unique index),
     * and a reversal is never itself reversed — re-record the posting instead.
     */
    public function void(BudgetEnvelopePosting $posting, string $reason, int $adminUserId): BudgetEnvelopePosting
    {
        return DB::transaction(function () use ($posting, $reason, $adminUserId) {
            $this->lockEnvelopes();
            $locked = BudgetEnvelopePosting::query()->with('lines')->whereKey($posting->id)->lockForUpdate()->firstOrFail();

            if ($locked->reverses_posting_id !== null) {
                $this->reject('posting', 'A void reversal cannot itself be voided — record the posting again instead.');
            }

            if (BudgetEnvelopePosting::query()->where('reverses_posting_id', $locked->id)->exists()) {
                $this->reject('posting', 'This posting has already been voided.');
            }

            if ($this->loanEffect($locked->type, $locked->fund_type, $locked->amount_sen) > 0) {
                $owed = $this->loanBalanceSen($locked->counterparty);
                if ($owed < $locked->amount_sen) {
                    $this->reject('posting', sprintf('Voiding this would leave the company owing %s a negative amount (RM%s owed now) — void the repayment first.', $locked->counterparty->label(), number_format($owed / 100, 2)));
                }
            }

            return $this->write(
                $locked->type,
                -$locked->amount_sen,
                $locked->lines->map(fn (BudgetEnvelopeEntry $l) => ['budget_envelope_id' => $l->budget_envelope_id, 'amount_sen' => -$l->amount_sen])->all(),
                $locked->transaction_date->toDateString(),
                "Void: {$reason} (reversing posting #{$locked->id})",
                $adminUserId,
                $locked->counterparty,
                $locked->fund_type,
                $locked->expense_category,
                null,
                null,
                reversesPostingId: $locked->id,
                voidReason: $reason,
            );
        });
    }

    /**
     * Decision 7: what the company owes one director, computed from postings,
     * never stored. A reversal carries a negated amount, so it nets itself out.
     */
    public function loanBalanceSen(PaidFrom $director): int
    {
        return (int) BudgetEnvelopePosting::query()
            ->where('counterparty', $director->value)
            ->get(['type', 'fund_type', 'amount_sen'])
            ->sum(fn (BudgetEnvelopePosting $p) => $this->loanEffect($p->type, $p->fund_type, $p->amount_sen));
    }

    /** @return array<string, int> keyed by director value, every director present */
    public function loanBalances(): array
    {
        return collect(PaidFrom::directors())
            ->mapWithKeys(fn (PaidFrom $d) => [$d->value => $this->loanBalanceSen($d)])
            ->all();
    }

    /**
     * PR-1 keeps the manual monthly action; the month close (ADR-083
     * 2026-10-10 addendum, decision 9, PR-2) replaces it.
     *
     * @param  array<int, int>  $allocations  [budget_envelope_id => amount_sen]
     */
    public function allocateMonthlyProfit(array $allocations, string $periodLabel, int $adminUserId): BudgetEnvelopePosting
    {
        return $this->post(
            EnvelopePostingType::ProfitAllocation,
            collect($allocations)->map(fn (int $amount, int $id) => ['budget_envelope_id' => $id, 'amount_sen' => $amount])->values()->all(),
            now(ReportService::TIMEZONE)->toDateString(),
            "Monthly profit allocation — {$periodLabel}",
            $adminUserId,
        );
    }

    public function downloadReceipt(BudgetEnvelopePosting $posting)
    {
        if ($posting->receipt_path === null) {
            abort(404);
        }

        return Storage::disk(config('filesystems.accounting_disk'))->download(
            $posting->receipt_path,
            "envelope-posting-{$posting->id}-receipt",
        );
    }

    /** How this posting moves its counterparty's loan balance. */
    private function loanEffect(EnvelopePostingType $type, ?FundType $fundType, int $amountSen): int
    {
        return match (true) {
            $type === EnvelopePostingType::Funding && $fundType === FundType::Loan,
            $type === EnvelopePostingType::DirectorPaidExpense => $amountSen,
            $type === EnvelopePostingType::Repayment => -$amountSen,
            default => 0,
        };
    }

    /**
     * The header's own size: what came in, moved, or went out. Signed only
     * for a profit allocation (a loss month allocates negatively, PR-2).
     *
     * @param  list<array{budget_envelope_id: int, amount_sen: int}>  $lines
     */
    private function headerAmount(EnvelopePostingType $type, array $lines): int
    {
        $amounts = array_column($lines, 'amount_sen');

        return match ($type) {
            EnvelopePostingType::Funding, EnvelopePostingType::ProfitAllocation => array_sum($amounts),
            EnvelopePostingType::Transfer, EnvelopePostingType::DirectorPaidExpense => array_sum(array_filter($amounts, fn (int $a) => $a > 0)),
            EnvelopePostingType::Expense, EnvelopePostingType::Repayment, EnvelopePostingType::Distribution => -$amounts[0],
        };
    }

    private function assertHeader(EnvelopePostingType $type, ?PaidFrom $counterparty, ?FundType $fundType, ?ExpenseCategory $expenseCategory): void
    {
        if ($type->requiresCounterparty() && $counterparty === null) {
            $this->reject('counterparty', 'Choose the director this belongs to.');
        }
        if (! $type->requiresCounterparty() && $counterparty !== null) {
            $this->reject('counterparty', "A {$type->label()} has no counterparty.");
        }
        if ($counterparty === PaidFrom::CompanyAccount) {
            $this->reject('counterparty', 'The counterparty must be a director, not the company account.');
        }

        $isFunding = $type === EnvelopePostingType::Funding;
        if ($isFunding && $fundType === null) {
            $this->reject('fund_type', 'Choose whether this is a loan or share capital.');
        }
        if (! $isFunding && $fundType !== null) {
            $this->reject('fund_type', 'Only funding has a fund type.');
        }

        if ($type->requiresExpenseCategory() && $expenseCategory === null) {
            $this->reject('expense_category', 'Choose an expense category.');
        }
        if (! $type->requiresExpenseCategory() && $expenseCategory !== null) {
            $this->reject('expense_category', "A {$type->label()} has no expense category.");
        }
    }

    /** @param list<array{budget_envelope_id: int, amount_sen: int}> $lines */
    private function assertLines(EnvelopePostingType $type, array $lines): void
    {
        $amounts = array_column($lines, 'amount_sen');
        $envelopeIds = array_column($lines, 'budget_envelope_id');
        $positive = array_filter($amounts, fn (int $a) => $a > 0);
        $negative = array_filter($amounts, fn (int $a) => $a < 0);

        if ($lines === [] || in_array(0, $amounts, true)) {
            $this->reject('lines', 'Every line needs a non-zero amount.');
        }

        // A director-paid expense is the one shape that hits the same envelope twice.
        if ($type !== EnvelopePostingType::DirectorPaidExpense && count(array_unique($envelopeIds)) !== count($envelopeIds)) {
            $this->reject('lines', 'Each envelope can appear only once in a posting.');
        }

        $ok = match ($type) {
            EnvelopePostingType::Funding => count($negative) === 0,
            EnvelopePostingType::ProfitAllocation => true,
            EnvelopePostingType::Transfer => count($lines) >= 2 && $positive !== [] && $negative !== [] && array_sum($amounts) === 0,
            EnvelopePostingType::Expense, EnvelopePostingType::Repayment, EnvelopePostingType::Distribution => count($lines) === 1 && $negative !== [],
            EnvelopePostingType::DirectorPaidExpense => count($lines) === 2
                && $envelopeIds[0] === $envelopeIds[1]
                && count($positive) === 1 && count($negative) === 1
                && array_sum($amounts) === 0,
        };

        if (! $ok) {
            $this->reject('lines', match ($type) {
                EnvelopePostingType::Funding => 'Funding only adds money to envelopes.',
                EnvelopePostingType::ProfitAllocation => '',
                EnvelopePostingType::Transfer => 'A transfer takes money out of at least one envelope and puts the same total into another.',
                EnvelopePostingType::DirectorPaidExpense => 'A director-paid expense is one envelope, the same amount in (the loan) and out (the expense).',
                default => "A {$type->label()} takes money out of exactly one envelope.",
            });
        }
    }

    /**
     * One global lock over every envelope serialises all postings, so a
     * repayment's "is it within the loan balance" check can't race another
     * posting for the same director.
     *
     * ponytail: global lock — a founder-only screen with a handful of
     * postings a month. Lock per counterparty if this ever becomes busy.
     *
     * @return Collection<int, BudgetEnvelope>
     */
    private function lockEnvelopes(): Collection
    {
        return BudgetEnvelope::query()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /** @param list<array{budget_envelope_id: int, amount_sen: int}> $lines */
    private function write(
        EnvelopePostingType $type,
        int $amount,
        array $lines,
        string $transactionDate,
        string $description,
        int $adminUserId,
        ?PaidFrom $counterparty,
        ?FundType $fundType,
        ?ExpenseCategory $expenseCategory,
        ?string $referenceNo,
        ?string $receiptPath,
        ?int $reversesPostingId = null,
        ?string $voidReason = null,
    ): BudgetEnvelopePosting {
        $posting = BudgetEnvelopePosting::query()->create([
            'type' => $type->value,
            'amount_sen' => $amount,
            'transaction_date' => $transactionDate,
            'description' => $description,
            'counterparty' => $counterparty?->value,
            'fund_type' => $fundType?->value,
            'expense_category' => $expenseCategory?->value,
            'reference_no' => $referenceNo,
            'receipt_path' => $receiptPath,
            'reverses_posting_id' => $reversesPostingId,
            'void_reason' => $voidReason,
            'created_by' => $adminUserId,
        ]);

        foreach ($lines as $line) {
            $posting->lines()->create($line);
        }

        return $posting->load('lines');
    }

    private function reject(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }
}
