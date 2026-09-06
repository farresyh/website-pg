<?php

namespace App\Services\Affiliate\Domain;

use Illuminate\Support\Facades\Log;

/**
 * ADR-060 (2026-09-06 "domain lifecycle" addendum): the fallback bound
 * whenever `services.vercel` is unconfigured — local dev, CI, and the
 * fast test suite (a real custom-domain end-to-end is verified against
 * production + the founder's reserved verification domain, never locally).
 *
 * It lets the whole lifecycle run — a row is created, listed, re-checked,
 * removed — without a network call. `attach()` succeeds and the domain
 * stays `pending` forever (no verification will ever land), which is
 * exactly the right local behaviour: the portal screen is navigable, and
 * nothing is ever falsely reported `active`.
 */
final class NullDomainProvider implements AffiliateDomainProvider
{
    public function attach(string $hostname): DomainProviderState
    {
        Log::info('NullDomainProvider::attach (services.vercel unconfigured) — domain will stay pending', [
            'hostname' => $hostname,
        ]);

        return new DomainProviderState(providerRef: $hostname, verified: false);
    }

    public function refresh(string $hostname): DomainProviderState
    {
        return new DomainProviderState(providerRef: $hostname, verified: false);
    }

    public function detach(string $hostname): void
    {
        Log::info('NullDomainProvider::detach (no-op)', ['hostname' => $hostname]);
    }
}
