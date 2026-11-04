<?php

namespace App\Http\Requests\Campaign;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-118 V12: a campaign is born with its first fee row (RM0 allowed). */
class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'monthly_fee_sen' => ['required', 'integer', 'min:0', 'max:100000000'],
            'from_month' => ['required', 'date_format:Y-m'],
            'to_month' => ['nullable', 'date_format:Y-m'],
        ];
    }
}
