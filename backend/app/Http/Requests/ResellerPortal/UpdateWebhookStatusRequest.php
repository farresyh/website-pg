<?php

namespace App\Http\Requests\ResellerPortal;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-084 PR-3 decision 4: pause / resume the delivery webhook without deleting its URL + secret. */
class UpdateWebhookStatusRequest extends FormRequest
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
            'is_active' => ['required', 'boolean'],
        ];
    }
}
