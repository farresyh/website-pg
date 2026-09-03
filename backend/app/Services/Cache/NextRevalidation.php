<?php

namespace App\Services\Cache;

use App\Jobs\PurgeNextCatalogCache;

/**
 * ADR-071 PR2 — the seam that tells the Next.js storefront to drop its
 * `catalog` Data-Cache tag. Called next to every `forgetCache()` /
 * `forgetPackagesCache()` / etc. in the catalog/SEO/branding/hero/
 * payment controllers, so the same admin save that busts this backend's
 * `Cache::remember()` also busts the storefront's cache one layer up.
 *
 * Public + static, matching the `CatalogController::forgetIndexCache()`
 * pattern it sits beside — this is a cheap, stateless fire-and-forget
 * with no second implementation to justify a full injected service.
 *
 * A no-op when `services.next.revalidate_*` is unconfigured (local dev,
 * CI, the test suite): the storefront still self-refreshes on its 60s
 * TTL. The actual HTTP POST is a queued, deduplicated, best-effort job
 * (`PurgeNextCatalogCache`) — never inline on the request thread
 * (backend/AGENTS.md).
 */
final class NextRevalidation
{
    public static function purge(): void
    {
        $url = config('services.next.revalidate_url');
        $secret = config('services.next.revalidate_secret');

        if (! is_string($url) || $url === '' || ! is_string($secret) || $secret === '') {
            return;
        }

        PurgeNextCatalogCache::dispatch()->afterCommit();
    }
}
