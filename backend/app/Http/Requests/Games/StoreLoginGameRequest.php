<?php

namespace App\Http\Requests\Games;

use App\Services\Catalog\GameFulfilmentRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ADR-125 decision 33: games were created only by the category link
 * (UID games, from a supplier category). A login game has no supplier
 * category, so it is created here with its credential schema. It starts
 * inactive and delivered manually; the admin adds manual packages, then
 * activates it through UpdateGameRequest.
 */
class StoreLoginGameRequest extends FormRequest
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
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash', Rule::unique('games', 'slug')],
            'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'delivery_subtext' => ['required', 'string', 'max:255'],
            'member_pricing_enabled' => ['required', 'boolean'],
            'credential_fields' => ['required', 'array', 'min:1', 'max:'.GameFulfilmentRule::MAX_FIELDS],
            ...GameFulfilmentRule::schemaRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                GameFulfilmentRule::validateSchema($validator, (array) $this->input('credential_fields'));
            }
        });
    }
}
