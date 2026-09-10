<?php

namespace App\Http\Requests\ResellerPortal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-084 PR-4 (decision 6/10): a Reseller portal user edits one API
 * key's IP allowlist. An empty / omitted list means "any IP" — the
 * allowlist is opt-in, so a serverless or shared-infra reseller still
 * works (ADR-084 PR-1 decision 6). Exact-match only for now; CIDR is a
 * documented future addition.
 */
class UpdateApiKeyRequest extends FormRequest
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
