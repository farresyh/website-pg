<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-083 2026-10-10 addendum, decisions 9–14 — structure only. Whether the
 * month may close, the allocation total, every cash account being present
 * and the gap note are `MonthCloseService::close()`'s rules.
 */
class CloseAccountingMonthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', Rule::in(range(1, 12))],
            'lines' => ['present', 'array', 'max:20'],
            'lines.*.budget_envelope_id' => ['required', 'integer', 'exists:budget_envelopes,id'],
            'lines.*.amount_sen' => ['required', 'integer', 'between:-1000000000,1000000000', 'not_in:0'],
            'cash_balances' => ['present', 'array', 'max:20'],
            'cash_balances.*.cash_account_id' => ['required', 'integer', 'exists:cash_accounts,id'],
            'cash_balances.*.balance_sen' => ['required', 'integer', 'between:-1000000000,1000000000'],
            'gap_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
