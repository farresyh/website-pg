<?php

namespace App\Services\Accounting;

/**
 * ADR-083 2026-09-30 addendum (Bucket C, decision 5/8) — which real-world
 * account funded a money-out event. Shared between Supplier Funding
 * (`supplier_transfers.paid_by`) and the Envelope Ledger
 * (`budget_envelope_entries.paid_from`) — the same small, known set of
 * payers funds both, so one enum instead of two independently-maintained
 * lists. A fixed list (not free text), same convention
 * `BudgetEnvelopeEntryCategory` already set for a stable known set of
 * values. Optional everywhere it's used — never forced on a category
 * where "which account" genuinely isn't known/relevant.
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
}
