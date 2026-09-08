<?php

namespace App\Http\Middleware;

use App\Services\Cors\ActiveCustomDomainOrigins;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-078 decision 1: fold the active custom affiliate-domain origins
 * (ADR-060) into `cors.allowed_origins` for the current request, on top
 * of `config/cors.php`'s static first-party list.
 *
 * Prepended to the global middleware stack so it runs *before*
 * `Illuminate\Http\Middleware\HandleCors`, which re-reads `config('cors')`
 * per request. Rebinding `Fruitcake\Cors\CorsService` (the ADR's first
 * sketch) does not work: `HandleCors::handle()` calls
 * `CorsService::setOptions(config('cors'))` unconditionally on every
 * matched request, overwriting anything a custom service pre-computed —
 * and it has no `Request`, so it cannot tell a real CORS request from a
 * plain one. Mutating config here, gated on the `Origin` header, keeps
 * the DB-backed lookup off every non-CORS request. Recorded as the
 * ADR-078 build addendum.
 *
 * Cheap on the hot path: no `Origin` header → immediate pass; an already
 * first-party `Origin` → immediate pass; only a genuinely third-party
 * `Origin` reaches the (60s-cached) active-domain list.
 */
class AllowActiveCustomDomainCors
{
    public function __construct(private readonly ActiveCustomDomainOrigins $origins) {}

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        if ($origin !== null) {
            $allowed = (array) config('cors.allowed_origins', []);

            if (! in_array($origin, $allowed, true) && in_array($origin, $this->origins->all(), true)) {
                config(['cors.allowed_origins' => [...$allowed, $origin]]);
            }
        }

        return $next($request);
    }
}
