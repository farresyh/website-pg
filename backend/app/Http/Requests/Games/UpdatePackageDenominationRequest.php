<?php

namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ADR-034 decision 3: an admin sets/clears a Package's inherent
 * denomination (e.g. diamond/UC amount) at promote or edit time. A
 * dedicated endpoint, matching UpdatePackageMarkupRequest/
 * UpdatePackageStatusRequest's own per-field pattern rather than
 * bundling into UpdatePackageRequest's rename-only form.
 *
 * ADR-075's catalog-code addendum (2026-09-04): `denomination` and
 * `catalog_code` (UpdatePackageCatalogCodeRequest) are mutually
 * exclusive on one Package — setting this to a non-null value while
 * the package already carries a `catalog_code` is rejected, same as
 * the reverse on the sibling endpoint.
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('denomination') === null) {
                return;
            }

            $package = $this->route('package');
            if ($package !== null && $package->catalog_code !== null) {
                $validator->errors()->add(
                    'denomination',
                    'This package already has a catalog_code set — clear it first.',
                );
            }
        });
    }
}
