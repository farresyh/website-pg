<?php

namespace App\Http\Requests\Admin;

use App\Models\ResellerTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ADR-072/073 PR-B: edit a `reseller_tiers` row. All fields optional — a
 * PATCH-style partial update. Changing `markup_percent` affects every
 * assigned reseller's wallet-debit price on their next order (ADR-073
 * decision 4, pricing resolved at order-placement time).
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
            'markup_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999.99'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'show_on_price_list' => ['sometimes', 'boolean'],
        ];
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
