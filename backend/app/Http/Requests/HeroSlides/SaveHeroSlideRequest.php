<?php

namespace App\Http\Requests\HeroSlides;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared shape for create (store) and edit (update) — unlike Games/
 * Packages there's no split-by-concern reason to separate them (no
 * money field, no supplier-sync boundary), just one full-row form.
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
            'eyebrow' => ['nullable', 'string', 'max:255'],
            // Reversed 2026-09-19 (ADR-060 PR-6 addendum): an asset-only
            // slide (all copy baked into the image) is now valid — the
            // 'required_without:image_url' guard below is the only thing
            // still standing between a slide and being fully blank.
            'title' => ['nullable', 'required_without:image_url', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'price_from_sen' => ['nullable', 'integer', 'min:0'],
            // A CTA is either fully present or fully absent — never a
            // label with no destination or a href with no visible button.
            'primary_cta_label' => ['nullable', 'required_with:primary_cta_href', 'string', 'max:255'],
            'primary_cta_href' => ['nullable', 'required_with:primary_cta_label', 'string', 'max:2048'],
            'secondary_cta_label' => ['nullable', 'required_with:secondary_cta_href', 'string', 'max:255'],
            'secondary_cta_href' => ['nullable', 'required_with:secondary_cta_label', 'string', 'max:2048'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }
}
