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
 */
class LinkSupplierProductCategoryRequest extends FormRequest
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
        });
    }
}
