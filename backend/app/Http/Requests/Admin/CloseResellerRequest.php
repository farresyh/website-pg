<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-131 decision 14: the closure is recorded after the refund was sent, so the form
 * carries the day it was sent and the bank's reference for it.
 */
class CloseResellerRequest extends FormRequest
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
            'transferred_on' => ['required', 'date_format:Y-m-d'],
            'bank_reference' => ['required', 'string', 'min:3', 'max:100'],
        ];
    }
}
