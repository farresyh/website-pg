<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-084 PR-3 decision 10: admin sets/updates a Reseller's delivery-webhook URL for support. HTTPS only. */
class StoreResellerWebhookRequest extends FormRequest
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
            'url' => ['required', 'string', 'url:https', 'max:2048'],
        ];
    }
}
