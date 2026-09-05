<?php

namespace App\Http\Middleware;

use App\Models\AffiliateUser;
use App\Services\Auth\AccountOwnerType;
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
 * Activates CurrentAffiliate from that user's `owner_id` — never from a
 * request parameter (ADR-057 decision 1) — only when `owner_type` is
 * `affiliate` (ADR-072 decision 5 / PR-G: `affiliate_users` is
 * polymorphic now). Every BelongsToAffiliate model query in the request
 * is then constrained to this tenant; a AffiliateUser somehow reaching
 * here with no resolvable owner would leave CurrentAffiliate
 * active-with-no-id, which AffiliateScope fails closed on (zero rows),
 * so there is no fail-open path.
 *
 * A `reseller`-owned account has no meaning under ADR-057's tenant
 * scope at all (`Reseller`/`BelongsToAffiliate` are unrelated concepts)
 * — this middleware 403s it rather than silently no-op-activating,
 * same defense-in-depth posture ADR-072 decision 4 calls for
 * (`EnsureAccountType` is the primary gate on every route; this is a
 * second, independent check specific to the one middleware that would
 * otherwise mis-scope a wrong-owner-type session).
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

        if ($user->owner_type !== AccountOwnerType::Affiliate) {
            throw new HttpException(403, 'Forbidden: not an affiliate account.');
        }

        $this->current->activate($user->owner_id);

        try {
            return $next($request);
        } finally {
            $this->current->deactivate();
        }
    }
}
