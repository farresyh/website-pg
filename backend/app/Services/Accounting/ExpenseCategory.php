<?php

namespace App\Services\Accounting;

/** ADR-083 2026-10-10 addendum, decision 3 — the sub-category of an expense posting. */
enum ExpenseCategory: string
{
    case Advertising = 'advertising';
    case Software = 'software';
    case ProfessionalFees = 'professional_fees';
    case Salary = 'salary';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Advertising => 'Advertising',
            self::Software => 'Software / tools',
            self::ProfessionalFees => 'Professional fees',
            self::Salary => 'Salary',
            self::Other => 'Other',
        };
    }
}
