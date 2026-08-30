<?php

namespace App\Http\Middleware;

use App\Models\ResellerUser;
use App\Support\CurrentReseller;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ADR-058 (58a): the seam that switches on ADR-057's tenant scope for a
 * real reseller-portal request. Runs after `auth:reseller` has resolved
 * the token, so `$request->user()` is the authenticated ResellerUser.
 *
 * Activates CurrentReseller from that user's `reseller_id` — never from
 * a request parameter (ADR-057 decision 1). Every BelongsToReseller
 * model query in the request is then constrained to this tenant; a
 * ResellerUser somehow reaching here with no `reseller_id` would leave
 * CurrentReseller active-with-no-id, which ResellerScope fails closed on
 * (zero rows), so there is no fail-open path.
 *
 * Deactivates on the way out. The `CurrentReseller` binding is
 * request-scoped and resets on its own between requests / queue jobs;
 * the explicit deactivate() keeps terminable middleware and post-response
 * logging from seeing a stale tenant.
 */
class SetResellerContext
{
    public function __construct(private readonly CurrentReseller $current) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof ResellerUser) {
            throw new HttpException(403, 'Forbidden: not a reseller user.');
        }

        if (! $user->is_active) {
            throw new HttpException(403, 'Forbidden: account deactivated.');
        }

        $this->current->activate($user->reseller_id);

        try {
            return $next($request);
        } finally {
            $this->current->deactivate();
        }
    }
}
