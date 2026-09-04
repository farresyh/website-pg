<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-058 58b (RES-5): activate / deactivate an affiliate. Deactivation
 * is a business kill switch — the ADR-060 branded storefront returns 503
 * and the Cloudflare custom hostname is suspended (that side is ADR-060,
 * not wired here). Existing earnings stay withdrawable; no new orders.
 */
class UpdateAffiliateStatusRequest extends FormRequest
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
            'status' => ['required', 'in:active,inactive'],
        ];
    }
}
