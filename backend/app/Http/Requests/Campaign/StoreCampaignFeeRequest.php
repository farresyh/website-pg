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
            // ADR-118 V26: a share of recognised sales in basis points (200 = 2%) and an optional monthly cap.
            // The floor rule (V29) lives in CampaignFeeSchedule, which reads the live setting.
            'revenue_share_bp' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'revenue_share_cap_sen' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'from_month' => ['required', 'date_format:Y-m'],
            'to_month' => ['nullable', 'date_format:Y-m'],
        ];
    }
}
