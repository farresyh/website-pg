<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-028 decision 4/8. `currency` is intentionally not validated
 * against a list of currencies — it's locked to 'MYR' in the admin UI
 * (Phase 2 multi-currency) and this endpoint isn't meant to be the
 * place that changes, so accepting whatever string is sent here is
 * fine; nothing downstream reads it yet.
 */
class UpdatePlatformSettingsRequest extends FormRequest
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
            'maintenance_mode' => ['required', 'boolean'],
            'maintenance_message' => ['nullable', 'string'],
            'telegram_notifications_enabled' => ['required', 'boolean'],
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
