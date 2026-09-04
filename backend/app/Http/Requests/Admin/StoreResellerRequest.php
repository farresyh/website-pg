<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-072/073 PR-B: a super_admin registers a new prepaid-wallet
 * `Reseller` account. Admin-created only, no self-serve signup (ADR-072
 * decision 6). Optionally assigns an initial tier at creation — a direct
 * FK, no subscription state machine (ADR-073 decision 1). No portal-login
 * user is created here (PR-G's polymorphic `reseller_users`, unbuilt).
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
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'reseller_tier_id' => ['nullable', 'integer', Rule::exists('reseller_tiers', 'id')->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
