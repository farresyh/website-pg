<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-072/073 PR-B: edit a `reseller_tiers` row. All fields optional — a
 * PATCH-style partial update. Changing `markup_percent` affects every
 * assigned reseller's wallet-debit price on their next order (ADR-073
 * decision 4, pricing resolved at order-placement time).
 */
class UpdateResellerTierRequest extends FormRequest
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
            'markup_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999.99'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
