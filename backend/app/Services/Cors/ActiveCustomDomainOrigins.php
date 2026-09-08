<?php

namespace App\Services\Cors;

use App\Models\AffiliateDomain;
use App\Services\Affiliate\AffiliateDomainStatus;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-078 decision 1: the browser `Origin`s that a custom affiliate
 * storefront domain is served on. `config/cors.php` `allowed_origins` is
 * a static list of the first-party Next apps; a custom domain attached to
 * an affiliate's branded storefront (ADR-060) is never in it, so every
 * `"use client"` call from that domain gets no `Access-Control-Allow-Origin`
 * and the browser blocks it.
 *
 * Only `Active` `affiliate_domains` rows are servable (an affiliate's
 * primary hostnames are deploy config, `STOREFRONT_PRIMARY_HOSTS`, never
 * rows — so this list is exactly the third-party custom domains). The
 * result is a plain array of `https://{hostname}` strings, cached for
 * {@see self::TTL_SECONDS}s and busted by {@see self::flush()} — which
 * `AffiliateDomainService` calls on every domain state change (ADR-078
 * PR-2). The cache is the safety net; the bust is the fast path.
 *
 * Read on every `api/*` request (via {@see DynamicCorsService}), so it
 * must stay a single cache GET on the warm path and never more than one
 * DB query per TTL window per process.
 */
class ActiveCustomDomainOrigins
{
    public const CACHE_KEY = 'cors:active-custom-domain-origins';

    public const TTL_SECONDS = 60;

    /**
     * @return list<string> e.g. ['https://shop.brand.com', 'https://toko.lain.my']
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, function (): array {
            return AffiliateDomain::withoutAffiliateScope()
                ->where('status', AffiliateDomainStatus::Active)
                ->orderBy('hostname')
                ->pluck('hostname')
                ->map(fn (string $hostname): string => 'https://'.$hostname)
                ->all();
        });
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
