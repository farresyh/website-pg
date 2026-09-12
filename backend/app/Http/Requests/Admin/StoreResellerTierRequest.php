<?php

namespace App\Http\Requests\Admin;

use App\Models\ResellerTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ADR-072/073 PR-B: CRUD for the `reseller_tiers` ladder. Admin-CRUD,
 * starts empty. `markup_percent` is applied over supplier cost_price
 * (ADR-073 decision 1) — no `monthly_fee_sen`, unlike
 * `StoreAffiliateTierRequest`.
 *
 * ADR-091: `show_on_price_list` is capped at 3 `true` rows across the
 * whole ladder — a founder-set business limit (keep the public price
 * list to a short, legible column count), not a DB constraint.
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
            'show_on_price_list' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->boolean('show_on_price_list')) {
                return;
            }

            if (ResellerTier::query()->where('show_on_price_list', true)->count() >= 3) {
                $validator->errors()->add(
                    'show_on_price_list',
                    'Only 3 tiers can be shown on the price list at once — remove one before adding another.',
                );
            }
        });
    }
}
