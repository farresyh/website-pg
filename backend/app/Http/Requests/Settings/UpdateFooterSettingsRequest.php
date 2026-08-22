<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-028 addendum decision 10/12/14. `terms_content`/`privacy_content`/
 * `about_us_content` are sanitized in the controller (the `rich_text`
 * Purifier profile, decision 12) — not here, since a FormRequest's
 * `rules()` validates shape, it doesn't transform the value.
 * `footer_game_ids` — decision 14: at most 4, each a currently-active
 * Game.
 */
class UpdateFooterSettingsRequest extends FormRequest
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
            'footer_text' => ['nullable', 'string'],
            'terms_content' => ['nullable', 'string'],
            'privacy_content' => ['nullable', 'string'],
            'about_us_content' => ['nullable', 'string'],
            'footer_game_ids' => ['nullable', 'array', 'max:4'],
            'footer_game_ids.*' => [
                'integer',
                Rule::exists('games', 'id')->where('is_active', true),
            ],
        ];
    }
}
