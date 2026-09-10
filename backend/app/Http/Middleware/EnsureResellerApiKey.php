<?php

namespace App\Http\Middleware;

use App\Exceptions\ResellerApi\ResellerApiException;
use App\Models\ResellerApiKey;
use App\Services\Reseller\ResellerApiKeyService;
use Closure;
use Illuminate\Http\Request;
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
    public function __construct(private readonly ResellerApiKeyService $apiKeys) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw ResellerApiException::missingApiKey();
        }

        // Resolves + stamps `last_used_at` / `last_used_ip` on a hit — the
        // stamp lands even for a request that is then rejected on the IP
        // allowlist below, which is exactly the anomaly signal a reseller
        // watches the portal for.
        $key = $this->apiKeys->resolve($token, $request->ip());
        if ($key === null) {
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
