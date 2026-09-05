<?php

namespace App\Http\Requests\ResellerPortal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PR-G planning addendum decision 7: a Reseller portal user issues
 * their own named API key (capped at 5 active, enforced in the
 * controller). Same shape `Admin\StoreResellerApiKeyRequest` uses for
 * the admin-side equivalent action.
 */
class StoreApiKeyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
