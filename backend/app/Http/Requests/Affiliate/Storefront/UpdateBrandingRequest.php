<?php

namespace App\Http\Requests\Affiliate\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-060 PR-6: the affiliate's self-serve store-identity fields —
 * mirrors the admin `Settings\UpdateBrandingRequest` shape (ADR-028),
 * minus the footer/legal fields, which stay admin-central.
 */
class UpdateBrandingRequest extends FormRequest
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
            'store_name' => ['required', 'string', 'max:255'],
            'theme_preset' => ['nullable', 'string', 'in:default,bumblebee,redgiants,emerald,cobalt'],
            // ADR-090: only `default` ships a dark palette today — the
            // portal's ThemeTab hides "Dark" for any preset without a
            // `tokensDark`, but this stays the actual enforcement.
            'theme_mode' => ['nullable', 'string', 'in:light,dark'],
            'description' => ['nullable', 'string', 'max:2000'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:32'],
            'telegram_contact_link' => ['nullable', 'string', 'max:2048'],
            'social_links' => ['nullable', 'array'],
            'social_links.facebook' => ['nullable', 'string', 'max:2048'],
            'social_links.instagram' => ['nullable', 'string', 'max:2048'],
            'social_links.tiktok' => ['nullable', 'string', 'max:2048'],
            'social_links.youtube' => ['nullable', 'string', 'max:2048'],
            'social_links.whatsapp' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
