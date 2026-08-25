<?php

namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-034 decision 3: an admin sets/clears a Package's inherent
 * denomination (e.g. diamond/UC amount) at promote or edit time. A
 * dedicated endpoint, matching UpdatePackageMarkupRequest/
 * UpdatePackageStatusRequest's own per-field pattern rather than
 * bundling into UpdatePackageRequest's rename-only form.
 */
class UpdatePackageDenominationRequest extends FormRequest
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
            'denomination' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
