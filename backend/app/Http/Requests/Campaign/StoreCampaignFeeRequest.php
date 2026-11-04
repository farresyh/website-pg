<?php

namespace App\Http\Requests\Campaign;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-118 V12: a renegotiation is a new fee row from its month. */
class StoreCampaignFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'monthly_fee_sen' => ['required', 'integer', 'min:0', 'max:100000000'],
            'from_month' => ['required', 'date_format:Y-m'],
            'to_month' => ['nullable', 'date_format:Y-m'],
        ];
    }
}
