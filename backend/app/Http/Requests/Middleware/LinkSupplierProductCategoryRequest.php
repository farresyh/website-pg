<?php

namespace App\Http\Requests\Middleware;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Links every raw `supplier_products` row sharing one `category_raw`
 * value (e.g. "Free Fire Global", ~15 items) to a Game in one action —
 * either an existing Game or a brand-new one created inline. This
 * decision is made ONCE per category, not per item (founder feedback,
 * docs/prd.md §14).
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
            'category_raw' => ['required', 'string'],
            'game_id' => ['nullable', 'required_without:new_game', 'integer', 'exists:games,id'],
            'new_game' => ['nullable', 'required_without:game_id', 'array'],
            'new_game.name' => ['required_with:new_game', 'string', 'max:255'],
            'new_game.category' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->filled('game_id') && $this->filled('new_game.name')) {
                $validator->errors()->add('game_id', 'Provide either game_id or new_game, not both.');
            }
        });
    }
}
