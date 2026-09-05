<?php

namespace App\Http\Controllers\ResellerPortal;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Reseller;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Base for every Reseller (wallet) portal controller (ADR-072/073
 * PR-G). All of these routes sit behind `auth:affiliate` +
 * `account.type:reseller` — `$request->user()` is the authenticated
 * `AffiliateUser`; `reseller()` is the one place that resolves its
 * owning `Reseller` back, so a controller never re-derives it.
 */
abstract class Controller extends BaseController
{
    protected function reseller(Request $request): Reseller
    {
        $reseller = $request->user()->resellerOwner();

        if ($reseller === null) {
            // Structurally shouldn't happen — account.type:reseller
            // already confirmed owner_type === reseller — but a loud
            // 404 beats a null-pointer crash if the owning row was
            // somehow deleted out from under a still-valid token.
            throw new HttpException(404, 'Reseller account not found.');
        }

        return $reseller;
    }
}
