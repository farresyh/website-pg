<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * ADR-131 decision 20: a branded domain that CHIP has not approved asked to start a payment.
 * Renders itself as a coded 403 so the storefront can say so (and not retry), the same
 * `code` + `message` shape `ResolveStorefrontBrand` and `checkout_closed` use.
 */
final class PaymentsNotEnabledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Payments are not yet enabled on this store.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['code' => 'payments_not_enabled', 'message' => $this->getMessage()], 403);
    }
}
