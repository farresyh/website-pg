<?php

namespace App\Http\Middleware;

use App\Models\AffiliateUser;
use App\Services\Auth\AccountOwnerType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ADR-072 decision 4 / PR-G: the mandatory backend authorization gate on
 * every reseller-guard endpoint — the portal must never rely on hiding a
 * nav tab as its only defense against, e.g., a `Reseller` (wallet)
 * account calling an `Affiliate`-only endpoint (Withdrawal, Subscription)
 * or vice versa.
 *
 * Runs after the `affiliate` guard's own auth (`auth:affiliate`), so
 * `$request->user()` is already the authenticated `AffiliateUser` —
 * checks its `owner_type` matches the route's own declared type,
 * 403s otherwise. Registered as the `account.type` alias
 * (`bootstrap/app.php`), parameterized: `account.type:affiliate` /
 * `account.type:reseller`.
 */
class EnsureAccountType
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $user = $request->user();

        if (! $user instanceof AffiliateUser) {
            throw new HttpException(403, 'Forbidden: not a portal account.');
        }

        // A misconfigured route (a typo'd middleware argument) fails
        // loud, same posture AccountOwnerType::coerce()'s \ValueError
        // already takes at this auth boundary — never silently allow.
        $expected = AccountOwnerType::coerce($type);

        if ($user->owner_type !== $expected) {
            throw new HttpException(403, "Forbidden: this endpoint is for {$expected->value} accounts only.");
        }

        return $next($request);
    }
}
