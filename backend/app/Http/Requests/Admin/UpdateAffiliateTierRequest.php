<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-058 58b / ADR-056 decision 1: edit a `affiliate_membership_tiers`
 * row. All fields optional — a PATCH-style partial update. Changing
 * `markup_percent` affects every subscribed affiliate's wholesale price
 * on their next order (pricing is computed at order time, ORD-9);
 * existing orders are untouched.
 */
class UpdateAffiliateTierRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'monthly_fee_sen' => ['sometimes', 'required', 'integer', 'min:0'],
            'markup_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999.99'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
