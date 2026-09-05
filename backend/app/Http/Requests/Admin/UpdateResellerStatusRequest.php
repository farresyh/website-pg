<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-072 decision 9: activate / deactivate a `Reseller` (wallet)
 * account. A deactivated reseller cannot place new orders on either
 * channel, but the wallet balance stays untouched and refundable by the
 * admin — this does not freeze or zero it.
 */
class UpdateResellerStatusRequest extends FormRequest
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
