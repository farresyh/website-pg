<?php

namespace App\Http\Requests\Campaign;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-118 V10: the code form's margin-floor preview. */
class CampaignMarginCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'discount_percent' => ['required', 'numeric', 'min:0.01', 'max:50'],
            'max_discount_sen' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
