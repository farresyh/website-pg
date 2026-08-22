<?php

namespace App\Http\Requests\Seo;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-029 decision 1/18: per-game SEO fields — data stays on Game, only
 * the editing surface moved (decision 11). Character limits match
 * decision 18's 70/160 Google-truncation counters.
 */
class UpdateGameSeoRequest extends FormRequest
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
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_title_local' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:160'],
            'seo_description_local' => ['nullable', 'string', 'max:160'],
            'seo_keywords' => ['nullable', 'string', 'max:500'],
            'seo_og_image' => ['nullable', 'string', 'max:2048'],
            'schema_brand' => ['nullable', 'string', 'max:255'],
            'schema_category' => ['nullable', 'string', 'max:255'],
            'no_index' => ['sometimes', 'boolean'],
        ];
    }
}
