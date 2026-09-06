<?php

namespace App\Http\Requests\Affiliate\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-060 PR-6 — an affiliate's own hero slide. Multipart: the text
 * fields plus an `image` file re-encoded to WebP by `ImageIngestService`
 * (decision 4). No `starts_at`/`ends_at` schedule window for affiliates
 * (Q11) — `is_active` only. `image` is required on create, optional on
 * edit (keep the current one).
 */
class SaveHeroSlideRequest extends FormRequest
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
            'image' => [
                $this->isMethod('POST') ? 'required' : 'nullable',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
                'dimensions:max_width=5000,max_height=5000',
            ],
            'eyebrow' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_from_sen' => ['nullable', 'integer', 'min:0'],
            // The `hero_slides` table has NOT NULL CTA columns and a
            // slide with no working button is a dead banner — required,
            // same as the admin form.
            'primary_cta_label' => ['required', 'string', 'max:255'],
            'primary_cta_href' => ['required', 'string', 'max:2048'],
            'secondary_cta_label' => ['nullable', 'string', 'max:255'],
            'secondary_cta_href' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:99'],
        ];
    }
}
