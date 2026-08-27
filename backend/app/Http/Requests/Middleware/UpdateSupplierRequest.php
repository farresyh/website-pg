<?php

namespace App\Http\Requests\Middleware;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-046 decision 3: `api_config` here is a raw array — the
 * per-supplier secret-vs-visible field structuring (masking secrets,
 * showing base_url/sandbox/testing as plain toggles) is a frontend
 * concern; the backend just stores whatever shape that supplier's
 * adapter expects. `slug` is deliberately not editable here — changing
 * which adapter a Supplier row resolves to after packages/orders
 * already reference it is not a supported operation.
 */
class UpdateSupplierRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'api_config' => ['sometimes', 'array'],
        ];
    }
}
