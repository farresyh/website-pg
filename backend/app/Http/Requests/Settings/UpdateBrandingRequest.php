<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-028 decision 2 (narrowed by the addendum decision 10). */
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
            'description' => ['nullable', 'string'],
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
