<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-058 58b (RES-3): edit a reseller's business details and markup
 * ceiling. Tier changes go through the dedicated assign-tier endpoint
 * (it writes an audit row); status changes go through updateStatus.
 * `markup_pct` is the reseller's own downstream margin and stays
 * editable here; `max_markup_pct` is the admin-set ceiling the portal
 * (ADR-059) will clamp the reseller's self-service edits to.
 */
class UpdateResellerRequest extends FormRequest
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
        ];
    }
}
