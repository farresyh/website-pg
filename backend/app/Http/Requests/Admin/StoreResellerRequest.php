<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-072/073 PR-B: a super_admin registers a new prepaid-wallet
 * `Reseller` account. ADR-133 decision 21: the tier is required (a direct
 * FK, ADR-073 decision 1) and the contact name and email become the first
 * portal login, so the email must not already belong to any portal user
 * (decision 5, closed accounts included).
 */
class StoreResellerRequest extends FormRequest
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
            'business_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('affiliate_users', 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
            'reseller_tier_id' => ['required', 'integer', Rule::exists('reseller_tiers', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
