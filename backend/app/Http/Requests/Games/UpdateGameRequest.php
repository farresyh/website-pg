<?php

namespace App\Http\Requests\Games;

use App\Models\Game;
use App\Services\Catalog\GameFulfilmentRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
 *
 * `credential_max_days`: ADR-126 2026-10-23 addendum, decision 22 — a
 * login game's own wipe ceiling; a change moves its unwiped logins.
 *
 * `family` / `region_label`: ADR-091 2026-11-01 addendum, decision 3 —
 * display-only grouping for /price-list. Trimmed and single-spaced; a
 * family reuses the spelling another game already has (matched ignoring
 * case); a region needs a family; no two games share both.
 *
 * `member_pricing_enabled`: ADR-125 decision 7. A login game is held to
 * GameFulfilmentRule::loginGameSettings() (decisions 5/33/46).
 */
class UpdateGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** ADR-091 2026-11-01, decision 3: tidy the grouping fields before validating. */
    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['family', 'region_label'] as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                $merge[$field] = is_string($value) ? (trim((string) preg_replace('/\s+/', ' ', $value)) ?: null) : $value;
            }
        }

        if (is_string($merge['family'] ?? null)) {
            $existing = Game::query()
                ->whereKeyNot($this->route('game')?->getKey())
                ->whereRaw('LOWER(family) = ?', [mb_strtolower($merge['family'])])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->value('family');
            $merge['family'] = $existing ?? $merge['family'];
        }

        $this->merge($merge);
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
            'family' => ['nullable', 'string', 'max:60'],
            'region_label' => ['nullable', 'string', 'max:40'],
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
            'member_pricing_enabled' => ['sometimes', 'boolean'],
            // ADR-126 decision 22: keep a login at most this many days; blank = the policy's D. Same bounds as D.
            'credential_max_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:90'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Game $game */
            $game = $this->route('game');

            $this->validateGrouping($validator, $game);

            if ($validator->errors()->isNotEmpty() || ! $game->isLoginGame()) {
                return;
            }

            foreach (GameFulfilmentRule::loginGameSettings($this->all(), $game) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
    }

    /** ADR-091 2026-11-01, decision 3: a region needs a family, and `(family, region)` is unique among games. */
    private function validateGrouping(Validator $validator, Game $game): void
    {
        $family = $this->input('family', $game->family);
        $region = $this->input('region_label', $game->region_label);

        if ($region !== null && $family === null) {
            $validator->errors()->add('region_label', 'A region needs a family.');

            return;
        }

        if ($family === null || $region === null) {
            return;
        }

        $taken = Game::query()
            ->whereKeyNot($game->getKey())
            ->whereRaw('LOWER(family) = ?', [mb_strtolower((string) $family)])
            ->whereRaw('LOWER(region_label) = ?', [mb_strtolower((string) $region)])
            ->exists();

        if ($taken) {
            $validator->errors()->add('region_label', 'Another game in this family already has this region.');
        }
    }
}
