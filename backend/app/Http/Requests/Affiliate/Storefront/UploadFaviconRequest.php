<?php

namespace App\Http\Requests\Affiliate\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-089: stricter than the plain logo `UploadImageRequest` — a
 * favicon must be square and at least 512x512 so Next.js's `app/icon`
 * convention has enough source resolution to derive every standard
 * browser/OS icon size from it. Reused from both the affiliate portal
 * (`Affiliate\Storefront\BrandingController`) and the admin Settings
 * screen (`Admin\SettingsController`, primary brand) — same shared-guard
 * reasoning as `UploadImageRequest`.
 */
class UploadFaviconRequest extends FormRequest
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
                'dimensions:min_width=512,min_height=512,max_width=5000,max_height=5000,ratio=1/1',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'image.dimensions' => 'The favicon must be a square image at least 512x512px.',
        ];
    }
}
