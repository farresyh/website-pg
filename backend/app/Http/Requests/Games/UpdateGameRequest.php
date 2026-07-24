<?php

namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GAME-3/GAME-4: admin edits a Game's own display fields and
 * active/inactive status. Deliberately excludes `supplier_mappings`/
 * `validation_rules` (raw JSON, PRD §14 flags free-typed JSON here as
 * a real typo risk — a future structured editor, not this endpoint)
 * and SEO fields (separate concern, not part of this pass).
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
            'category' => ['nullable', 'string', 'max:255'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'banner_url' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
