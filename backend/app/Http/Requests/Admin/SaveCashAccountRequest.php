<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** ADR-083 2026-10-10 addendum, decision 14 — add, rename or archive a cash account. Name only, never an account number. */
class SaveCashAccountRequest extends FormRequest
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
        $creating = $this->route('cashAccount') === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:191', Rule::unique('cash_accounts', 'name')->ignore($this->route('cashAccount'))],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
