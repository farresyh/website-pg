<?php

namespace App\Http\Requests\Membership;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-027's 2026-08-29 addendum, decision 20 — a dedicated lightweight
 * endpoint for flipping PlatformSettings.membership_enabled (the actual
 * pre-launch kill switch), matching PackageController::updateStatus's
 * own inline-toggle precedent rather than requiring the /admin/membership
 * screen to round-trip the whole unrelated Settings/Platform form.
 */
class UpdateMembershipEnabledRequest extends FormRequest
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
            'membership_enabled' => ['required', 'boolean'],
        ];
    }
}
