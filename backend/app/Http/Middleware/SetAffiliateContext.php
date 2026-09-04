<?php

namespace App\Http\Middleware;

use App\Models\AffiliateUser;
use App\Support\CurrentAffiliate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ADR-058 (58a): the seam that switches on ADR-057's tenant scope for a
 * real affiliate-portal request. Runs after `auth:affiliate` has resolved
 * the token, so `$request->user()` is the authenticated AffiliateUser.
 *
 * Activates CurrentAffiliate from that user's `affiliate_id` — never from
 * a request parameter (ADR-057 decision 1). Every BelongsToAffiliate
 * model query in the request is then constrained to this tenant; a
 * AffiliateUser somehow reaching here with no `affiliate_id` would leave
 * CurrentAffiliate active-with-no-id, which AffiliateScope fails closed on
 * (zero rows), so there is no fail-open path.
 *
 * Deactivates on the way out. The `CurrentAffiliate` binding is
 * request-scoped and resets on its own between requests / queue jobs;
 * the explicit deactivate() keeps terminable middleware and post-response
 * logging from seeing a stale tenant.
 */
class SetAffiliateContext
{
    public function __construct(private readonly CurrentAffiliate $current) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof AffiliateUser) {
            throw new HttpException(403, 'Forbidden: not an affiliate user.');
        }

        if (! $user->is_active) {
            throw new HttpException(403, 'Forbidden: account deactivated.');
        }

        $this->current->activate($user->affiliate_id);

        try {
            return $next($request);
        } finally {
            $this->current->deactivate();
        }
    }
}
