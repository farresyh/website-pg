<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-058 58b (RES-3 / ADR-056 decision 8): assign or change a
 * reseller's wholesale-tier subscription. The controller writes a
 * `reseller_tier_changes` audit row via ResellerSubscriptionService.
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
            'tier_id' => ['required', 'integer', Rule::exists('reseller_membership_tiers', 'id')->whereNull('deleted_at')],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
