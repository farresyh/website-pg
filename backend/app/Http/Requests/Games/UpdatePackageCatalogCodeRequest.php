<?php

namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ADR-075's catalog-code addendum (2026-09-04), decision 2: an admin
 * sets/clears a bundle/pass Package's `catalog_code` — the equivalence
 * key used in place of `denomination` (ADR-034) for products with no
 * inherent numeric value. Dedicated endpoint, same per-field pattern
 * as UpdatePackageDenominationRequest/UpdatePackageMarkupRequest.
 *
 * Format: must contain at least one non-digit character (never a
 * bare numeric string) so a reseller product code's trailing segment
 * (`{reseller_code}-{denomination-or-catalog_code}`) always parses
 * unambiguously — an all-digit remainder resolves via `denomination`,
 * anything else via `catalog_code`. Normalized to uppercase, mirroring
 * `reseller_code` (UpdateGameRequest).
 *
 * Mutually exclusive with `denomination` — see the sibling request's
 * own doc comment for the reverse direction of this same check.
 */
class UpdatePackageCatalogCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('catalog_code') && $this->input('catalog_code') !== null) {
            $this->merge(['catalog_code' => strtoupper((string) $this->input('catalog_code'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'catalog_code' => ['nullable', 'string', 'max:20', 'regex:/^(?=.*[A-Z])[A-Z0-9]+$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('catalog_code') === null) {
                return;
            }

            $package = $this->route('package');
            if ($package !== null && $package->denomination !== null) {
                $validator->errors()->add(
                    'catalog_code',
                    'This package already has a denomination set — clear it first.',
                );
            }
        });
    }
}
