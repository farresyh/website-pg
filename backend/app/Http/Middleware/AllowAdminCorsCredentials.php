<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-121: the admin app calls the API with its session cookie, which needs
 * `Access-Control-Allow-Credentials`. Turned on for that origin only, per
 * request, so no other origin (storefront, portal, a custom affiliate
 * domain, ADR-078) ever gets it. Prepended before HandleCors, which re-reads
 * `config('cors')` per request (see AllowActiveCustomDomainCors).
 */
class AllowAdminCorsCredentials
{
    public function handle(Request $request, Closure $next): Response
    {
        // Set both ways every request, so a long-lived worker never carries
        // a previous request's `true` over.
        config(['cors.supports_credentials' => in_array(
            $request->headers->get('Origin'),
            (array) config('cors.credentialed_origins', []),
            true,
        )]);

        return $next($request);
    }
}
