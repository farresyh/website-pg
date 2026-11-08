<?php

namespace App\Http\Requests\Admin;

use App\Models\ResellerTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ADR-072/073 PR-B: edit a `reseller_tiers` row. All fields optional — a
 * PATCH-style partial update.
 *
 * ADR-139 2026-11-08 (PR 2) addendum: `markup_percent` is fixed once the
 * tier exists. An edit would re-price every reseller on it mid-month and
 * detach the rebate ladder from the margin it was set on. A different
 * markup is a new tier, moved to from the next 1st.
 *
 * ADR-091: same 3-tier cap on `show_on_price_list` as
 * `StoreResellerTierRequest`, excluding this row itself so re-saving an
 * already-shown tier never trips its own count.
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
            'markup_percent' => ['prohibited'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'show_on_price_list' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['markup_percent.prohibited' => 'A tier\'s markup is fixed. Create a new tier with the markup you want and move resellers to it (it takes effect on the 1st).'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->has('show_on_price_list') || ! $this->boolean('show_on_price_list')) {
                return;
            }

            $current = $this->route('reseller_tier');
            $alreadyShown = $current instanceof ResellerTier && $current->show_on_price_list;

            if ($alreadyShown) {
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
