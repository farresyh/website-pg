<?php

namespace App\Http\Middleware;

use App\Exceptions\ResellerApi\ResellerApiException;
use App\Models\ResellerApiKey;
use App\Services\Reseller\ResellerApiKeyService;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-074 decision 1: the Reseller API channel's own auth boundary — a
 * bearer `reseller_api_keys` credential, deliberately not the
 * portal-login `reseller` Sanctum guard ("can I view my dashboard" and
 * "can I spend real money placing orders" must never share one
 * credential).
 *
 * ADR-084 PR-1 decision 6: also enforces the optional per-key IP
 * allowlist and records `last_used_ip`. An empty / null allowlist means
 * "any IP" (opt-in) so a serverless or shared-infra reseller still works.
 * Every rejection is a stable `ResellerApiException` envelope, not a bare
 * `{message}`.
 *
 * On success, attaches the resolved `Reseller` to the request under the
 * `reseller` attribute — read it via the `ResellerApi\Controller` base
 * method.
 */
class EnsureResellerApiKey
{
    private const MAX_AUTH_FAILURES_PER_MINUTE = 20;

    public function __construct(private readonly ResellerApiKeyService $apiKeys) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Wave 3 PR-B: the `reseller-api` limiter keys on the raw token, so
        // each made-up key got a fresh bucket. Failed auth is counted per
        // IP here instead — 20/min, then 429 until the minute is up.
        $failureKey = 'reseller-api-auth-failed:'.$request->ip();
        if (RateLimiter::tooManyAttempts($failureKey, self::MAX_AUTH_FAILURES_PER_MINUTE)) {
            // bootstrap/app.php renders this as the standard RATE_LIMITED envelope, headers kept.
            throw new ThrottleRequestsException(headers: ['Retry-After' => RateLimiter::availableIn($failureKey)]);
        }

        $token = $request->bearerToken();
        if ($token === null) {
            RateLimiter::hit($failureKey);
            throw ResellerApiException::missingApiKey();
        }

        // Resolves + stamps `last_used_at` / `last_used_ip` on a hit — the
        // stamp lands even for a request that is then rejected on the IP
        // allowlist below, which is exactly the anomaly signal a reseller
        // watches the portal for.
        $key = $this->apiKeys->resolve($token, $request->ip());
        if ($key === null) {
            RateLimiter::hit($failureKey);
            throw ResellerApiException::invalidApiKey();
        }

        $reseller = $key->reseller;
        if ($reseller === null || ! $reseller->is_active) {
            throw ResellerApiException::resellerInactive();
        }

        if (! self::ipAllowed($key, $request->ip())) {
            throw ResellerApiException::ipNotAllowed();
        }

        $request->attributes->set('reseller', $reseller);

        return $next($request);
    }

    /**
     * @param  list<string>|null  $allowed  from `reseller_api_keys.allowed_ips`
     */
    private static function ipAllowed(ResellerApiKey $key, ?string $ip): bool
    {
        $allowed = $key->allowed_ips;

        if ($allowed === null || $allowed === []) {
            return true;
        }

        return $ip !== null && in_array($ip, $allowed, true);
    }
}
