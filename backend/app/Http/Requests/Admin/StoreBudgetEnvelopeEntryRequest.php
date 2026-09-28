<?php

namespace App\Http\Requests\Admin;

use App\Services\Accounting\BudgetEnvelopeEntryCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-083 2026-09-28 "Envelope Ledger" addendum. `amount_sen` is always
 * a positive magnitude here — "beginner friendly" was an explicit
 * founder requirement, and asking a non-technical director to type a
 * signed number correctly (and get the sign wrong on an OPEX row) is
 * exactly the kind of mistake this avoids. The category's own
 * `typicalSign()` supplies the sign in the controller; `direction` is
 * only required for `Adjustment`, the one category allowed either way.
 */
class StoreBudgetEnvelopeEntryRequest extends FormRequest
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
            'category' => ['required', 'string', Rule::in(array_map(fn (BudgetEnvelopeEntryCategory $c) => $c->value, BudgetEnvelopeEntryCategory::cases()))],
            'amount_sen' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'description' => ['required', 'string', 'max:1000'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'direction' => ['required_if:category,'.BudgetEnvelopeEntryCategory::Adjustment->value, 'nullable', 'string', 'in:in,out'],
        ];
    }
}
