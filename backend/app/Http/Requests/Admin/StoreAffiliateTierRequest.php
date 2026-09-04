<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-058 58b / ADR-056 decision 1: CRUD for the `affiliate_membership_tiers`
 * ladder. Admin-CRUD, starts empty, not row-count-locked (contrast the
 * consumer `membership_plans`' fixed two rows). `monthly_fee_sen` is an
 * integer in sen (backend/AGENTS.md); `markup_percent` is applied over
 * supplier cost_price (ADR-056 decision 2).
 */
class StoreAffiliateTierRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'monthly_fee_sen' => ['required', 'integer', 'min:0'],
            'markup_percent' => ['required', 'numeric', 'min:0', 'max:999.99'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }
}
