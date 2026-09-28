<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Rename and/or archive/reactivate — never a hard delete, see the migration's own doc comment. */
class UpdateBudgetEnvelopeRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:191', Rule::unique('budget_envelopes', 'name')->ignore($this->route('budgetEnvelope'))],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
