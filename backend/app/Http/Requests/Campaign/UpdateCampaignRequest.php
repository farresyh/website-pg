<?php

namespace App\Http\Requests\Campaign;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-118 V17: rename, or end (which stops its codes; attribution continues). */
class UpdateCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'ended' => ['sometimes', 'boolean'],
            'last_fee_month' => ['sometimes', 'date_format:Y-m'],
        ];
    }
}
