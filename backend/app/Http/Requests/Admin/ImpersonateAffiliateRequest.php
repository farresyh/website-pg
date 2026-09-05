<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-058 58b (RES-4): a super_admin opens an impersonation session for
 * an affiliate portal. The optional `reason` is stored on the audit row.
 * The controller mints a short-lived `affiliate`-guard Sanctum token
 * scoped with an `impersonate` ability against one of the affiliate's
 * active portal users.
 */
class ImpersonateAffiliateRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
