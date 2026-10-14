<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-121 decision 1: an admin cookie login lasts at most 7 days, however
 * active. (The 2-day idle limit is the session lifetime itself.) Applies
 * only to a `web`-session login; there is no other way to be an admin.
 */
class EnsureAdminSessionFresh
{
    public const MAX_AGE_SECONDS = 7 * 24 * 3600;

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('web')->check()) {
            $authenticatedAt = (int) $request->session()->get('authenticated_at', 0);

            if ($authenticatedAt < now()->timestamp - self::MAX_AGE_SECONDS) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();

                return response()->json(['message' => 'Your login has expired. Sign in again.'], 401);
            }
        }

        return $next($request);
    }
}
