<?php

namespace App\Services\Accounting;

/**
 * ADR-083 2026-10-10 addendum, decision 3 — a `funding` posting is either a
 * director's loan (a liability, counted in that director's loan balance) or
 * share capital (equity, never repaid as a loan).
 */
enum FundType: string
{
    case Loan = 'loan';
    case ShareCapital = 'share_capital';

    public function label(): string
    {
        return match ($this) {
            self::Loan => 'Loan',
            self::ShareCapital => 'Share capital',
        };
    }
}
