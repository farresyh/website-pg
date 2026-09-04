<?php

namespace App\Http\Requests\ResellerApi;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-074 decision 3: the Reseller API's order-create payload —
 * `product_code` (PR-E0's `{reseller_code}-{denomination-or-
 * catalog_code}` scheme, resolved by `ResellerCatalogService`
 * downstream, not validated against the DB here — an unknown code is
 * a 422 from the controller, not a validation-layer concern) plus the
 * same `idempotency_key` shape `CreateCheckoutRequest`/
 * `CreateVoucherRequest` already require.
 */
class PlaceOrderRequest extends FormRequest
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
            'product_code' => ['required', 'string', 'max:32'],
            'player_id' => ['required', 'string', 'max:64'],
            'server_id' => ['nullable', 'string', 'max:64'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ];
    }
}
