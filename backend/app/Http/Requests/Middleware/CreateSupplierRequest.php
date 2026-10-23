<?php

namespace App\Http\Requests\Middleware;

use App\Models\Supplier;
use App\Services\Supplier\SupplierAdapterFactory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-046 decision 4 + ADR-125 decision 1 (2026-10-18 addendum, decision
 * 26): `driver` must be a registered adapter. An API supplier's slug is
 * its driver — the webhook, replay rule and config lookups are keyed on
 * it — so it is set here, never typed. Only a manual supplier names its
 * own slug, and it is always MYR (decision 9) with a funding mode
 * (decision 18). A penjoki (ADR-126) is a pay-after manual supplier.
 */
class CreateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('driver') !== Supplier::DRIVER_MANUAL) {
            $this->merge(['slug' => $this->input('driver'), 'funding_mode' => Supplier::FUNDING_PREPAID]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $manual = $this->input('driver') === Supplier::DRIVER_MANUAL;

        return [
            'name' => ['required', 'string', 'max:255'],
            'driver' => ['required', 'string', Rule::in(app(SupplierAdapterFactory::class)->registeredDrivers())],
            'slug' => ['required', 'string', 'max:64', 'alpha_dash:ascii', 'unique:suppliers,slug'],
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'currency' => $manual ? ['required', 'in:MYR'] : ['required', 'string', 'size:3'],
            'funding_mode' => ['required', Rule::in([Supplier::FUNDING_PREPAID, Supplier::FUNDING_PAY_AFTER])],
            'is_active' => ['sometimes', 'boolean'],
            'api_config' => ['sometimes', 'array'],
            // ADR-126 decision 8: a penjoki is a pay-after manual supplier.
            // The pool is the migration's one row, never created here.
            'joki_role' => $manual && $this->input('funding_mode') === Supplier::FUNDING_PAY_AFTER
                ? ['nullable', Rule::in([Supplier::JOKI_PENJOKI])]
                : ['prohibited'],
        ];
    }
}
