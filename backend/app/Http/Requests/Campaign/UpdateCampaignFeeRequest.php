<?php

namespace App\Http\Requests\Campaign;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-118 V12: the end month is the one thing a fee row can change. */
class UpdateCampaignFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to_month' => ['present', 'nullable', 'date_format:Y-m'],
        ];
    }
}
