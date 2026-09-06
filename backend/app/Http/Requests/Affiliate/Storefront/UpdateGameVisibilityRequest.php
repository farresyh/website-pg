<?php

namespace App\Http\Requests\Affiliate\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-060 PR-6 — the per-game on/off switch on the Catalog tab. */
class UpdateGameVisibilityRequest extends FormRequest
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
            'is_visible' => ['required', 'boolean'],
        ];
    }
}
