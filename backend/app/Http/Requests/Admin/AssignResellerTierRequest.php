<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-073 decision 1: assign or change a reseller's wallet tier — a
 * direct FK swap, effective immediately, no billing cycle or audit trail
 * table (contrast `AssignAffiliateTierRequest`, which feeds a subscription
 * state machine + `affiliate_tier_changes` history).
 */
class AssignResellerTierRequest extends FormRequest
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
            'reseller_tier_id' => ['required', 'integer', Rule::exists('reseller_tiers', 'id')->whereNull('deleted_at')],
        ];
    }
}
