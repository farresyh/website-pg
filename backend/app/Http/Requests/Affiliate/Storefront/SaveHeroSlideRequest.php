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
            // Reversed 2026-09-19 (ADR-060 PR-6 addendum): an asset-only
            // slide is now valid — `image` is required on create (above),
            // so a brand-new slide always has an image even without a
            // title; edit-time title removal is fine too since the row
            // already carries an image.
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_from_sen' => ['nullable', 'integer', 'min:0'],
            // A CTA is either fully present or fully absent — never a
            // label with no destination or a href with no visible button.
            'primary_cta_label' => ['nullable', 'required_with:primary_cta_href', 'string', 'max:255'],
            'primary_cta_href' => ['nullable', 'required_with:primary_cta_label', 'string', 'max:2048'],
            'secondary_cta_label' => ['nullable', 'required_with:secondary_cta_href', 'string', 'max:255'],
            'secondary_cta_href' => ['nullable', 'required_with:secondary_cta_label', 'string', 'max:2048'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:99'],
        ];
    }
}
