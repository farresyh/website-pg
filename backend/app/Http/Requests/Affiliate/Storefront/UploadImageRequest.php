<?php

namespace App\Http\Requests\Affiliate\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-060 PR-6 (planning addendum decision 4): shared upload guard for
 * the logo and hero-slide image fields. `ImageIngestService` re-encodes
 * everything to WebP, so the mime allowlist is only about what the
 * decoder can read — SVG is rejected (GD/Intervention cannot rasterise
 * it). The `dimensions` cap bounds decoder memory before Intervention
 * ever loads the bitmap.
 */
class UploadImageRequest extends FormRequest
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
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048', // 2 MB, kilobytes
                'dimensions:max_width=5000,max_height=5000',
            ],
        ];
    }
}
