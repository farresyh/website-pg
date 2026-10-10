<?php

namespace App\Services\Accounting;

/**
 * ADR-083 2026-10-10 addendum, decision 3 — what one Envelope Ledger posting
 * is. The type decides which header fields are required and what shape its
 * lines must have; `BudgetEnvelopeService::post()` enforces both.
 */
enum EnvelopePostingType: string
{
    case Funding = 'funding';
    case ProfitAllocation = 'profit_allocation';
    case Transfer = 'transfer';
    case Expense = 'expense';
    case DirectorPaidExpense = 'director_paid_expense';
    case Repayment = 'repayment';
    case Distribution = 'distribution';

    public function label(): string
    {
        return match ($this) {
            self::Funding => 'Funding received',
            self::ProfitAllocation => 'Profit allocation',
            self::Transfer => 'Transfer between envelopes',
            self::Expense => 'Expense',
            self::DirectorPaidExpense => 'Expense paid by a director',
            self::Repayment => 'Loan repayment to a director',
            self::Distribution => 'Dividend',
        };
    }

    public function requiresCounterparty(): bool
    {
        return in_array($this, [self::Funding, self::DirectorPaidExpense, self::Repayment, self::Distribution], true);
    }

    public function requiresExpenseCategory(): bool
    {
        return $this === self::Expense || $this === self::DirectorPaidExpense;
    }

    /** Recorded by an admin on the Envelope Ledger; a profit allocation only comes from a month close (`MonthCloseService`). */
    public function isManual(): bool
    {
        return $this !== self::ProfitAllocation;
    }
}
