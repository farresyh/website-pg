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
 */
abstract class Controller extends BaseController
{
    protected function reseller(Request $request): Reseller
    {
        /** @var Reseller $reseller */
        $reseller = $request->attributes->get('reseller');

        return $reseller;
    }
}
