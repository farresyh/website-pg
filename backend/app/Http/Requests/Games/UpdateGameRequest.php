<?php

namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GAME-3/GAME-4: admin edits a Game's own display fields and
 * active/inactive status. Deliberately excludes `supplier_mappings`/
 * `validation_rules` — the latter is set at the /middleware/product-manager
 * category-link step (LinkSupplierProductCategoryRequest), a
 * supplier-integration decision made once per category, not a
 * catalog-display field edited here — and SEO fields (separate
 * concern, not part of this pass).
 *
 * `player_validator_profile_id`/`player_validator_enabled` ARE
 * included here (unlike `validation_rules`) even though both live on
 * the same `Game` row: whether the storefront shows a "Validate
 * Player ID" button is a catalog/customer-facing toggle, not a
 * supplier-integration data-shape decision — see the founder's own
 * distinction in docs/prd.md §14's Player-ID Validation NEXT SESSION
 * note (the `/admin/games` toggle was always the agreed home for
 * this, not /middleware).
 *
 * `reseller_code`: ADR-075's catalog-code addendum (2026-09-04),
 * decision 1 — the game segment of a Reseller API/Bot product code
 * (`{reseller_code}-{denomination-or-catalog_code}`). Uppercase
 * letters only, no digits/dashes, so a product code's trailing
 * segment always parses unambiguously; unique globally (not scoped
 * per-game) since it stands alone as a public identifier.
 *
 * `description`/`important_notes`/`delivery_mode`/`delivery_subtext`:
 * ADR-109's customer-facing info-modal + delivery-badge fields —
 * catalog-display, same side of the split as everything else in this
 * FormRequest.
 */
class UpdateGameRequest extends FormRequest
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
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('games', 'slug')->ignore($this->route('game')),
            ],
            'reseller_code' => [
                'nullable',
                'string',
                'regex:/^[A-Z]{2,10}$/',
                Rule::unique('games', 'reseller_code')->ignore($this->route('game')),
            ],
            'category' => ['nullable', 'string', 'max:255'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'banner_url' => ['nullable', 'string', 'max:2048'],
            'description' => ['nullable', 'string', 'max:2000'],
            'important_notes' => ['nullable', 'array'],
            'important_notes.*' => ['string', 'max:500'],
            'delivery_mode' => ['sometimes', Rule::in(['instant', 'manual'])],
            'delivery_subtext' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'player_validator_profile_id' => ['nullable', 'integer', Rule::exists('player_validator_profiles', 'id')],
            'player_validator_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
