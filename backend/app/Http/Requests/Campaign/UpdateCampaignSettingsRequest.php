<?php

namespace App\Http\Requests\Campaign;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-118 V19: the campaign settings; target CAC and trial length may be empty (then no verdict). */
class UpdateCampaignSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'campaign_min_margin_percent' => ['sometimes', 'numeric', 'min:0', 'max:50'],
            'campaign_qualified_min_sen' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'campaign_target_cac_sen' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'campaign_trial_months' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:24'],
        ];
    }
}
