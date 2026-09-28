<?php

namespace App\Services\Accounting;

/**
 * ADR-083 2026-09-28 "Envelope Ledger" addendum. Purely descriptive —
 * `BudgetEnvelopeEntry::amount_sen` carries the real signed value, this
 * never drives arithmetic on its own. `typicalSign()` only backs a
 * soft warning in the form request (catching an obvious mis-pick, e.g.
 * "OPEX Rent" typed as a positive inflow) — `Adjustment` is the one
 * category allowed either sign outright, mirroring
 * `SupplierLedgerEntryType::ManualAdjustment`.
 */
enum BudgetEnvelopeEntryCategory: string
{
    case CapitalInjection = 'capital_injection';
    case MonthlyProfitAllocation = 'monthly_profit_allocation';
    case CapitalRepayment = 'capital_repayment';
    case DividendDrawing = 'dividend_drawing';
    case OpexRent = 'opex_rent';
    case OpexAdvertising = 'opex_advertising';
    case OpexSoftware = 'opex_software';
    case OpexBankCharges = 'opex_bank_charges';
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
            self::OpexRent => 'OPEX — Rent',
            self::OpexAdvertising => 'OPEX — Advertising',
            self::OpexSoftware => 'OPEX — Software / Tools',
            self::OpexBankCharges => 'OPEX — Bank Charges',
            self::OpexProfessionalFees => 'OPEX — Professional Fees',
            self::OpexSalary => 'OPEX — Salary',
            self::OpexOther => 'OPEX — Other',
            self::Adjustment => 'Adjustment',
        };
    }
}
