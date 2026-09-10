<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-084 PR-4: admin edits one Reseller API key's IP allowlist for support (empty = any IP). */
class UpdateResellerApiKeyRequest extends FormRequest
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
            'allowed_ips' => ['present', 'array', 'max:20'],
            'allowed_ips.*' => ['ip'],
        ];
    }
}
