<?php

namespace App\Services\Accounting;

/**
 * The company's directors, plus the company account. Two uses:
 * - `supplier_transfers.paid_by`: which real account funded a supplier
 *   top-up (ADR-083 2026-09-30 addendum, Bucket C decision 5).
 * - `budget_envelope_postings.counterparty`: the director a loan, a
 *   director-paid expense, a repayment or a dividend belongs to (ADR-083
 *   2026-10-10 addendum, decision 7). Only `directors()` are valid there.
 */
enum PaidFrom: string
{
    case Farres = 'farres';
    case Lokman = 'lokman';
    case Wheng = 'wheng';
    case CompanyAccount = 'company_account';

    public function label(): string
    {
        return match ($this) {
            self::Farres => 'Farres (personal)',
            self::Lokman => 'Lokman (personal)',
            self::Wheng => 'Wheng (personal)',
            self::CompanyAccount => 'Company account',
        };
    }

    /** @return list<self> */
    public static function directors(): array
    {
        return array_values(array_filter(self::cases(), fn (self $p) => $p !== self::CompanyAccount));
    }
}
