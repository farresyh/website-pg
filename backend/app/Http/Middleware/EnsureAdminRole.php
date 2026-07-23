<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * AUTH-2: every backend endpoint enforces role-based access control
 * server-side — frontend route guards are convenience only, never the
 * actual gate. Usage: ->middleware('admin.role:super_admin') or
 * ->middleware('admin.role:super_admin,admin') for either role.
 */
class EnsureAdminRole
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $admin = $request->user();

        // Throws HttpException directly rather than using the abort()
        // helper — abort() needs the application container booted,
        // which this middleware shouldn't require just to be tested.
        if (! $admin instanceof AdminUser || ! in_array($admin->role, $roles, true)) {
            throw new HttpException(403, 'Forbidden: insufficient role.');
        }

        if (! $admin->is_active) {
            throw new HttpException(403, 'Forbidden: account deactivated.');
        }

        return $next($request);
    }
}
