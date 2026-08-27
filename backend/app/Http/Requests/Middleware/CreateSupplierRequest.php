<?php

namespace App\Http\Requests\Middleware;

use App\Services\Supplier\SupplierAdapterFactory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-046 decision 4: `slug` is constrained to adapter slugs already
 * registered in SupplierAdapterFactory's container bindings — a typo'd
 * slug would otherwise sit as a silently-broken Supplier row until the
 * first real fulfillment/sync attempt needs it.
 */
class CreateSupplierRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:suppliers,slug', 'in:'.implode(',', app(SupplierAdapterFactory::class)->registeredSlugs())],
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'currency' => ['required', 'string', 'size:3'],
            'is_active' => ['sometimes', 'boolean'],
            'api_config' => ['sometimes', 'array'],
        ];
    }
}
