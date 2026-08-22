<?php

namespace App\Http\Requests\Seo;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-029 decision 2/8: SEO-2 defaults, SEO-3 template patterns, SEO-7 pixel IDs, JSON-LD toggles. */
class UpdateSeoSettingsRequest extends FormRequest
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
            'default_meta_title' => ['nullable', 'string', 'max:70'],
            'default_meta_description' => ['nullable', 'string', 'max:160'],
            'default_og_image' => ['nullable', 'string', 'max:2048'],
            'meta_title_template' => ['nullable', 'string', 'max:255'],
            'meta_description_template' => ['nullable', 'string', 'max:500'],
            'ga_measurement_id' => ['nullable', 'string', 'max:32'],
            'fb_pixel_id' => ['nullable', 'string', 'max:32'],
            'tiktok_pixel_id' => ['nullable', 'string', 'max:32'],
            'schema_organization_enabled' => ['sometimes', 'boolean'],
            'schema_product_enabled' => ['sometimes', 'boolean'],
            'schema_breadcrumb_enabled' => ['sometimes', 'boolean'],
            // Merged into every `is_allowed` crawler_rules row at
            // render time (SeoController::robots()) — not stored
            // per-bot, see that migration's own doc comment for why.
            'crawler_default_disallow_paths' => ['nullable', 'array'],
            'crawler_default_disallow_paths.*' => ['string', 'starts_with:/'],
        ];
    }
}
