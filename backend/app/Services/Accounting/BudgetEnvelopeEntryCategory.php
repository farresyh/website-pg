<?php

namespace App\Services\Accounting;

/**
 * ADR-083 2026-09-28 "Envelope Ledger" addendum. Purely descriptive —
 * `BudgetEnvelopeEntry::amount_sen` carries the real signed value, this
 * never drives arithmetic on its own. `typicalSign()` only backs a
 * soft warning in the form request (catching an obvious mis-pick, e.g.
 * "OPEX Salary" typed as a positive inflow) — `Adjustment` is the one
 * category allowed either sign outright, mirroring
 * `SupplierLedgerEntryType::ManualAdjustment`. Deliberately trimmed
 * 2026-09-28 (same-day follow-up, founder pushback): `OpexRent`
 * (confirmed no physical office exists) and `OpexBankCharges`
 * (no confirmed real recurring expense distinct from the fee already
 * captured on `supplier_transfers.fee_myr`) were removed rather than
 * kept "just in case" — add back with one enum case + one `label()`
 * arm if either ever becomes a real, confirmed expense. `OpexSalary`
 * and `OpexProfessionalFees` stayed despite not being in use yet —
 * the founder's own explicit call, since both map to a real, already-
 * planned future expense (staff salary; the year-end auditor/tax
 * agent engagement this whole ADR exists to prepare for) rather than
 * a speculative guess.
 */
enum BudgetEnvelopeEntryCategory: string
{
    case CapitalInjection = 'capital_injection';
    case MonthlyProfitAllocation = 'monthly_profit_allocation';
    case CapitalRepayment = 'capital_repayment';
    case DividendDrawing = 'dividend_drawing';
    case OpexAdvertising = 'opex_advertising';
    case OpexSoftware = 'opex_software';
    case OpexProfessionalFees = 'opex_professional_fees';
    case OpexSalary = 'opex_salary';
    case OpexOther = 'opex_other';
    case Adjustment = 'adjustment';

    /** @return 'positive'|'negative'|'either' */
    public function typicalSign(): string
    {
        return match ($this) {
            self::CapitalInjection, self::MonthlyProfitAllocation => 'positive',
            self::Adjustment => 'either',
            default => 'negative',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CapitalInjection => 'Capital Injection',
            self::MonthlyProfitAllocation => 'Monthly Profit Allocation',
            self::CapitalRepayment => 'Capital Repayment',
            self::DividendDrawing => 'Dividend / Drawing',
            self::OpexAdvertising => 'OPEX — Advertising',
            self::OpexSoftware => 'OPEX — Software / Tools',
            self::OpexProfessionalFees => 'OPEX — Professional Fees',
            self::OpexSalary => 'OPEX — Salary',
            self::OpexOther => 'OPEX — Other',
            self::Adjustment => 'Adjustment',
        };
    }
}
