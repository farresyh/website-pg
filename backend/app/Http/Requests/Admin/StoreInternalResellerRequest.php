<?php

namespace App\Http\Requests\Admin;

/**
 * ADR-133 decision 12(c): "Create internal account", for a test or internal account with no
 * KYC on file (FixFast for CHIP). The same fields as the normal create plus a required reason,
 * kept on the account and in the log. Behind super_admin and MFA step-up (the route).
 */
class StoreInternalResellerRequest extends StoreResellerRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
