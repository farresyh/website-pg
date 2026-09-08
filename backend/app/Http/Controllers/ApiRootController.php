<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * ADR-078 decision 4: the backend root. This is an API-only backend
 * (ADR-009/011) — `GET /` previously rendered Laravel's stock
 * `welcome.blade.php`, whose `@if (Route::has('login'))` / `route(...)`
 * calls surfaced in production Pulse as `RouteNotFoundException` on the
 * bot traffic that hits the bare domain. A flat JSON identity response
 * with no Blade, no `route()` and no closure removes that source and
 * keeps `php artisan route:cache` (run by the Forge deploy script)
 * unambiguously safe.
 *
 * Not `HealthController` — that one probes the DB + queue for infra
 * monitors (`/api/health`); this is only a "you reached the API" banner
 * and must never depend on anything.
 */
class ApiRootController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'service' => 'PekanGame API',
            'status' => 'ok',
        ]);
    }
}
