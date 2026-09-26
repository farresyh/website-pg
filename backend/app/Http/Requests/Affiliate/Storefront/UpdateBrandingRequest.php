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
            // This ID list must match the *affiliate-selectable* keys of
            // THEME_PRESETS in storefront/src/lib/theme-presets.ts (the
            // canonical source — ADR-081/090). PHP can't import the TS
            // file, so this stays a hand-kept 4th copy; update both
            // together when a preset is added/removed. `default` (Digital
            // Architect) is deliberately excluded — it's PekanGame's own
            // primary-brand identity, never an affiliate's option (founder
            // decision, 2026-09-24); the primary's own row is seeded
            // 'default' at the DB-column-default level, never through
            // this affiliate self-serve endpoint.
            'theme_preset' => ['nullable', 'string', 'in:bumblebee,redgiants,emerald,cobalt'],
            // ADR-090: dark palettes shipped for every preset 2026-09-24 —
            // the portal's ThemeTab hides "Dark" for any preset without a
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
