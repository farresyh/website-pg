<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-139 decision 36: in or out of the rebate programme, with the reason recorded. */
class UpdateRebateEligibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'eligible' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
