<?php

namespace App\Http\Middleware;

use App\Services\Reseller\ResellerApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ADR-074 decision 1: the Reseller API channel's own auth boundary —
 * a bearer `reseller_api_keys` credential, deliberately not the
 * portal-login `reseller` Sanctum guard (see the ADR's own
 * rationale: "can I view my dashboard" and "can I spend real money
 * placing orders" must never share one credential).
 *
 * On success, attaches the resolved `Reseller` to the request under
 * the `reseller` attribute — read it via `$request->attributes->
 * get('reseller')` (or the `ResellerApi\Controller` base method), the
 * same way `EnsureAdminRole` leaves `$request->user()` for
 * Sanctum-guarded routes to read.
 */
class EnsureResellerApiKey
{
    public function __construct(private readonly ResellerApiKeyService $apiKeys) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw new HttpException(401, 'Missing API key.');
        }

        $key = $this->apiKeys->resolve($token);
        if ($key === null) {
            throw new HttpException(401, 'Invalid or revoked API key.');
        }

        $reseller = $key->reseller;
        if ($reseller === null || ! $reseller->is_active) {
            throw new HttpException(403, 'This reseller account is deactivated.');
        }

        $request->attributes->set('reseller', $reseller);

        return $next($request);
    }
}
