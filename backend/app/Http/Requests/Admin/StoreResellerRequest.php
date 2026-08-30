<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-058 58b (RES-2): a super_admin registers a new reseller. Route
 * middleware (admin.role:super_admin) is the gate — authorize() stays
 * true. Creates the Reseller row, its first reseller_user (triggers the
 * set-password invite), and optionally assigns an initial wholesale
 * tier. Cross-field rules (max_markup_pct >= markup_pct) are here;
 * "does this tier exist / is it active" is left to the controller +
 * `exists`.
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
            'markup_pct' => ['required', 'numeric', 'min:0', 'max:999.99'],
            'max_markup_pct' => ['nullable', 'numeric', 'min:0', 'max:999.99', 'gte:markup_pct'],
            'domains' => ['nullable', 'array'],
            'domains.*' => ['string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // ADR-061 decision 8: "our own brand?" — internal brands may
            // enable consumer Membership; third-party resellers never can
            // (the controller forces membership_enabled off unless is_owned).
            // `is_primary` is not settable here — it is backfilled onto the
            // one pre-Phase-2 row and never reassigned via CRUD.
            'is_owned' => ['sometimes', 'boolean'],
            'membership_enabled' => ['sometimes', 'boolean'],
            'tier_id' => ['nullable', 'integer', Rule::exists('reseller_membership_tiers', 'id')->whereNull('deleted_at')],
            'user_name' => ['required', 'string', 'max:255'],
            'user_email' => ['required', 'email', 'max:255', Rule::unique('reseller_users', 'email')],
        ];
    }
}
