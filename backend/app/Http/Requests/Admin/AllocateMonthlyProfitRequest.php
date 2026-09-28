<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-083 2026-09-28 "Envelope Ledger" addendum — "Allocate Monthly
 * Profit". `allocations` is admin-typed by design: the founder decides
 * the split manually each month, never an automatic formula (the
 * founder's own explicit call during grilling — Q6).
 */
class AllocateMonthlyProfitRequest extends FormRequest
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
            'period_label' => ['required', 'string', 'max:100'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.budget_envelope_id' => ['required', 'integer', 'exists:budget_envelopes,id'],
            'allocations.*.amount_sen' => ['required', 'integer', 'min:1', 'max:1000000000'],
        ];
    }
}
