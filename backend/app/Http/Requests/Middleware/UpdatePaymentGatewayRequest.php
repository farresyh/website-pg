<?php

namespace App\Http\Requests\Middleware;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-110 PR-C, mirrors `UpdateSupplierRequest` — `api_config` is a raw
 * array here; the controller merges it onto whatever is already
 * stored (never a wholesale replace), so a blank/omitted secret field
 * leaves the existing value untouched rather than clearing it.
 */
class UpdatePaymentGatewayRequest extends FormRequest
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
            'api_config' => ['required', 'array'],
        ];
    }
}
