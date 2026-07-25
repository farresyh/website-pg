<?php

namespace App\Http\Requests\Middleware;

use Illuminate\Foundation\Http\FormRequest;

/**
 * MUI-5 — `validator_key`/`country_code` are immutable once a row
 * exists (they're the identity of the row, shown as a static label in
 * the UI, not an input) — only which Game it routes to, and the
 * admin-typed display label, are ever edited.
 */
class UpdatePlayerRegionMappingRequest extends FormRequest
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
            'country_name' => ['required', 'string', 'max:255'],
            'game_id' => ['required', 'integer', 'exists:games,id'],
        ];
    }
}
