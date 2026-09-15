<?php

namespace App\Http\Requests\Middleware;

use App\Models\Supplier;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Links every raw `supplier_products` row in one
 * `(supplier_id, group_label)` group (e.g. Gamevion / "Free Fire
 * Global", ~15 items) to a Game in one action — either an existing
 * Game or a brand-new one created inline. This decision is made ONCE
 * per group, not per item (founder feedback, docs/prd.md §14).
 * ADR-067 decision 6 replaced the old bare `category_raw` key with
 * `supplier_id` + `group_label` so a group belongs to exactly one
 * supplier.
 *
 * `validation_rules.extra_field` rides along in the same action
 * (founder feedback, 2026-07-25): a game's supplier order-submission
 * needs are a per-game constant, so the natural moment to set them is
 * the same "which Game" decision — not a separate trip to
 * /admin/games afterward. Structured/validated to a fixed enum (never
 * raw JSON), per legacy-reference-notes.md's "typo silently breaks
 * checkout" finding.
 *
 * `validation_rules.customer_no_separator` (ADR-097 decisions 2/20)
 * rides along the same way — restricted server-side to a Digiflazz-
 * linked category (decision 1's own scope), not just hidden client-
 * side, so a value set on a non-Digiflazz game can't silently sit
 * there never read by anything (the exact class of stale-config trap
 * this ADR's own Finding 1 already found once).
 *
 * `validation_rules.zone_options` (ADR-097 decisions 6-8/20/21) —
 * same pattern, restricted to `extra_field === 'zone_id'`, and
 * trimmed/deduped/empty-dropped in `prepareForValidation()`: Digiflazz
 * forwards whatever string an admin picks verbatim, so a stray space
 * or a duplicate entry is a silent real-order failure risk, not a
 * cosmetic one.
 */
class LinkSupplierProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $options = $this->input('validation_rules.zone_options');

        if (! is_array($options)) {
            return;
        }

        $normalized = collect($options)
            ->filter(fn ($value) => is_string($value))
            ->map(fn (string $value) => trim($value))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->values()
            ->all();

        $this->merge(['validation_rules' => array_merge(
            (array) $this->input('validation_rules', []),
            ['zone_options' => $normalized],
        )]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            // 'present', not 'required': '' is the legitimate group_label
            // for a row a supplier gave neither a category nor a brand.
            'group_label' => ['present', 'string'],
            'game_id' => ['nullable', 'required_without:new_game', 'integer', 'exists:games,id'],
            'new_game' => ['nullable', 'required_without:game_id', 'array'],
            'new_game.name' => ['required_with:new_game', 'string', 'max:255'],
            'new_game.category' => ['nullable', 'string', 'max:255'],
            'validation_rules' => ['nullable', 'array'],
            'validation_rules.extra_field' => ['nullable', Rule::in(['server_id', 'zone_id'])],
            'validation_rules.customer_no_separator' => ['nullable', Rule::in(['concat', 'space', 'pipe'])],
            'validation_rules.zone_options' => ['nullable', 'array'],
            'validation_rules.zone_options.*' => ['string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->filled('game_id') && $this->filled('new_game.name')) {
                $validator->errors()->add('game_id', 'Provide either game_id or new_game, not both.');
            }

            if ($this->filled('validation_rules.customer_no_separator')) {
                $supplier = Supplier::query()->find($this->input('supplier_id'));

                if ($supplier !== null && $supplier->slug !== 'digiflazz') {
                    $validator->errors()->add(
                        'validation_rules.customer_no_separator',
                        'customer_no_separator only applies to a Digiflazz-linked game.',
                    );
                }
            }

            $zoneOptions = $this->input('validation_rules.zone_options', []);
            if ($zoneOptions !== [] && $this->input('validation_rules.extra_field') !== 'zone_id') {
                $validator->errors()->add(
                    'validation_rules.zone_options',
                    'zone_options only applies when extra_field is zone_id.',
                );
            }
        });
    }
}
