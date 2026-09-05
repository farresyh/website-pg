<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-072/073 PR-B: CRUD for the `reseller_tiers` ladder. Admin-CRUD,
 * starts empty. `markup_percent` is applied over supplier cost_price
 * (ADR-073 decision 1) — no `monthly_fee_sen`, unlike
 * `StoreAffiliateTierRequest`.
 */
class StoreResellerTierRequest extends FormRequest
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
            'markup_percent' => ['required', 'numeric', 'min:0', 'max:999.99'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }
}
