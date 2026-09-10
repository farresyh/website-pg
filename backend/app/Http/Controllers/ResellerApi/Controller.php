<?php

namespace App\Http\Controllers\ResellerApi;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Reseller;
use Illuminate\Http\Request;

/**
 * Base for every Reseller API channel controller (ADR-074) — all of
 * them sit behind `EnsureResellerApiKey`, which attaches the
 * authenticated `Reseller` to the request. `reseller()` is the one
 * place that reads it back, so a controller never touches
 * `$request->attributes` directly.
 *
 * The `ERROR_*` constants are the representative envelope bodies the
 * `#[Response]` attributes on each endpoint reference (ADR-084 PR-1
 * decision 3 + 11) — one place, so the generated spec and the docs
 * site's Errors page never drift.
 */
abstract class Controller extends BaseController
{
    /** The `#[Response]` `type:` for every 4xx — the uniform error envelope (ADR-084 PR-1 decision 3). */
    protected const ERROR_SHAPE = 'array{error: string, message: string}';

    /** @var array{error: string, message: string} */
    protected const ERROR_401 = ['error' => 'MISSING_API_KEY', 'message' => 'An API key is required. Send it as a bearer token.'];

    /** @var array{error: string, message: string} */
    protected const ERROR_403 = ['error' => 'RESELLER_INACTIVE', 'message' => 'This reseller account is deactivated.'];

    /** @var array{error: string, message: string} */
    protected const ERROR_404 = ['error' => 'ORDER_NOT_FOUND', 'message' => 'No order was found for that order number.'];

    /** @var array{error: string, message: string} */
    protected const ERROR_409 = ['error' => 'IDEMPOTENCY_KEY_CONFLICT', 'message' => 'This idempotency_key was already used for an order with a different payload. Use a fresh key per logical order.'];

    /** @var array{error: string, message: string} */
    protected const ERROR_422 = ['error' => 'INSUFFICIENT_BALANCE', 'message' => 'Wallet balance is insufficient for this order.'];

    /** @var array{error: string, message: string} */
    protected const ERROR_429 = ['error' => 'RATE_LIMITED', 'message' => 'Too many requests. Retry after the period given in the Retry-After header.'];

    protected function reseller(Request $request): Reseller
    {
        /** @var Reseller $reseller */
        $reseller = $request->attributes->get('reseller');

        return $reseller;
    }
}
