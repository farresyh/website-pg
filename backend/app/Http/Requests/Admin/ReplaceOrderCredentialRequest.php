<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-124 decision 6: replace one secret field (customer typo, changed password). */
class ReplaceOrderCredentialRequest extends FormRequest
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
            'field' => ['required', 'string', 'max:64'],
            'value' => ['required', 'string', 'max:255'],
        ];
    }
}
