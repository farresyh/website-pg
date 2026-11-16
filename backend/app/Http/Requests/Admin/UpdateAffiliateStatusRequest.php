<?php

namespace App\Http\Requests\Admin;

use App\Services\Affiliate\AffiliateStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-058 58b (RES-5): activate / deactivate an affiliate. Deactivation
 * is a business kill switch: every custom domain is suspended, so the
 * branded storefront shows "store unavailable" (`AffiliateController::
 * updateStatus()`), and the portal turns read-only. Existing earnings stay
 * withdrawable; no new orders.
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
            'status' => ['required', Rule::enum(AffiliateStatus::class)],
        ];
    }
}
