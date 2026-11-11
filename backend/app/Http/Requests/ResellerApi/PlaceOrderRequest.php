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
            // Published: these comments become the request schema on docs.pekangame.space.
            /**
             * A `packages[].code` from `GET /v1/catalog`.
             *
             * @example MLMY-86
             */
            'product_code' => ['required', 'string', 'max:32'],
            /**
             * The player's game ID, as the game's `checkout_input.player_id_format` requires.
             *
             * @example 123456789
             */
            'player_id' => ['required', 'string', 'max:64'],
            /**
             * Only when the game's `checkout_input.field` is not `null`.
             *
             * @example 2201
             */
            'server_id' => ['nullable', 'string', 'max:64'],
            /**
             * One fresh UUID per logical order; reuse it only to retry that order.
             *
             * @example 8f2b8c4e-1d3a-4a9c-9b1e-2f6a7c0d5e11
             */
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
            /**
             * The most you agree to pay, in sen. If your current price is higher: `PRICE_CHANGED`, nothing charged.
             *
             * @example 6300
             */
            'max_price_sen' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
