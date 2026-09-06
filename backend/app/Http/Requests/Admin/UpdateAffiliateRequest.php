<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-058 58b (RES-3): edit an affiliate's business details and markup
 * ceiling. Tier changes go through the dedicated assign-tier endpoint
 * (it writes an audit row); status changes go through updateStatus.
 * `markup_pct` is the affiliate's own downstream margin and stays
 * editable here; `max_markup_pct` is the admin-set ceiling the portal
 * (ADR-059) will clamp the affiliate's self-service edits to.
 *
 * ADR-061: `is_owned` (our brand) and `membership_enabled` (the
 * per-brand consumer-Membership toggle — the founder's own switch for
 * an internal brand) are editable here, super_admin only. `is_primary`
 * is never reassigned through CRUD.
 */
class UpdateAffiliateRequest extends FormRequest
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
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_owned' => ['sometimes', 'boolean'],
            'membership_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
