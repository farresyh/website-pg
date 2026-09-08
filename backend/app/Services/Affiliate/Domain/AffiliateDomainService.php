<?php

namespace App\Services\Affiliate\Domain;

use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Cache\NextRevalidation;
use App\Services\Cors\ActiveCustomDomainOrigins;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-060 (2026-09-06 "domain lifecycle" addendum): the deep module for
 * an affiliate's custom-domain lifecycle. The portal controller, the
 * admin break-glass actions, the RES-5/RES-6 hooks, and the daily
 * reconcile command all go through here — never straight to
 * `AffiliateDomainProvider` and never writing an `affiliate_domains` row
 * by hand.
 *
 * This service is the SINGLE writer of `affiliate_domains`. The provider
 * only reports state (`DomainProviderState`); this maps it onto the row.
 *
 * Rows whose `provider_ref` is null are the primary affiliate's seeded
 * hostnames (addendum section I) — every provider-side step here skips
 * them, and they are never created/removed through this service.
 */
class AffiliateDomainService
{
    /** Addendum section D: a hard per-affiliate cap. */
    public const MAX_DOMAINS = 5;

    public function __construct(
        private readonly AffiliateDomainProvider $provider,
        private readonly ActiveCustomDomainOrigins $corsOrigins,
    ) {}

    /**
     * Portal self-serve add (addendum section D). Creates the row,
     * attaches it at the provider, and maps the result back. On a
     * provider failure the row is rolled back so the affiliate retries
     * cleanly.
     *
     * @throws ValidationException structural / business-rule rejection
     * @throws DomainProviderException provider-side failure (already sanitised)
     */
    public function add(Affiliate $affiliate, string $hostname): AffiliateDomain
    {
        $hostname = $this->normalise($hostname);

        $this->assertManageable($affiliate);
        $this->assertHostnameAllowed($hostname);

        if ($affiliate->customDomains()->count() >= self::MAX_DOMAINS) {
            throw ValidationException::withMessages([
                'hostname' => ['You can have at most '.self::MAX_DOMAINS.' domains. Remove one before adding another.'],
            ]);
        }

        try {
            $domain = $affiliate->customDomains()->create([
                'hostname' => $hostname,
                'status' => AffiliateDomainStatus::Pending,
                'provider' => 'vercel',
                'is_primary' => false,
            ]);
        } catch (QueryException $e) {
            // Globally-unique `hostname` — one hostname, one affiliate.
            if ($this->isUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'hostname' => ['That domain is already registered. Contact support if you believe this is a mistake.'],
                ]);
            }

            throw $e;
        }

        try {
            $state = $this->provider->attach($hostname);
        } catch (DomainProviderException $e) {
            $domain->forceDelete();

            throw $e;
        }

        $this->applyState($domain, $state);
        $this->propagateChange();

        return $domain->refresh();
    }

    /**
     * Re-read one domain from the provider and map the result. Used by
     * the portal "Check now" button (rethrows so the portal shows the
     * message) and the daily reconcile command (which catches per-row).
     *
     * A null-`provider_ref` row (the primary's seeded hostnames) is
     * returned untouched.
     *
     * @throws DomainProviderException
     */
    public function recheck(AffiliateDomain $domain): AffiliateDomain
    {
        if ($domain->provider_ref === null) {
            return $domain;
        }

        try {
            $state = $this->provider->refresh($domain->provider_ref);
        } catch (DomainProviderException $e) {
            $domain->forceFill([
                'last_checked_at' => now(),
                'last_error' => Str::limit($e->getMessage(), 250, ''),
            ])->save();

            throw $e;
        }

        $before = [$domain->status, $domain->is_primary];
        $this->applyState($domain, $state);

        if ([$domain->status, $domain->is_primary] !== $before) {
            $this->propagateChange();
        }

        return $domain->refresh();
    }

    /**
     * Portal / admin remove (addendum section F). Detaches at the
     * provider then deletes the row; if it was the primary, primary
     * fails over to another active domain.
     *
     * @throws DomainProviderException
     */
    public function remove(AffiliateDomain $domain): void
    {
        $affiliate = $domain->affiliate;
        $wasPrimary = $domain->is_primary;

        if ($domain->provider_ref !== null) {
            $this->provider->detach($domain->provider_ref);
        }

        $domain->forceDelete();

        if ($wasPrimary && $affiliate !== null) {
            $this->failoverPrimary($affiliate);
        }

        $this->propagateChange();
    }

    /**
     * Re-point the canonical primary among the affiliate's ACTIVE
     * domains (addendum section E). No-op if `$domain` is not active.
     */
    public function setPrimary(AffiliateDomain $domain): void
    {
        if ($domain->status !== AffiliateDomainStatus::Active) {
            throw ValidationException::withMessages([
                'domain' => ['Only an active domain can be made primary.'],
            ]);
        }

        DB::transaction(function () use ($domain) {
            $domain->affiliate->customDomains()
                ->whereKeyNot($domain->getKey())
                ->update(['is_primary' => false]);

            $domain->forceFill(['is_primary' => true])->save();
        });

        $this->propagateChange();
    }

    /**
     * RES-5 deactivate (addendum section G): every provider-managed row
     * → `suspended`; the storefront then returns 503 for that host. The
     * provider domain is left attached (DNS stays valid for a fast
     * reactivate).
     */
    public function suspendAll(Affiliate $affiliate): void
    {
        $affiliate->customDomains()
            ->whereNotNull('provider_ref')
            ->update(['is_primary' => false, 'status' => AffiliateDomainStatus::Suspended]);

        $this->propagateChange();
    }

    /**
     * RES-5 reactivate: suspended rows go back to `pending` and are
     * re-checked once (best-effort — a provider failure here is
     * swallowed, the daily poll will retry).
     */
    public function resumeAll(Affiliate $affiliate): void
    {
        $suspended = $affiliate->customDomains()
            ->whereNotNull('provider_ref')
            ->where('status', AffiliateDomainStatus::Suspended)
            ->get();

        foreach ($suspended as $domain) {
            $domain->forceFill(['status' => AffiliateDomainStatus::Pending])->save();

            try {
                $this->recheck($domain);
            } catch (DomainProviderException) {
                // Left pending — the daily reconcile command will retry.
            }
        }
    }

    /**
     * RES-6 delete (addendum section G): remove every provider domain,
     * then hard-delete the rows. (The addendum says "soft-delete with
     * the affiliate"; the model has no SoftDeletes and a hard delete
     * frees the globally-unique hostname for re-use, which RES-6's
     * earnings-zero precondition makes safe — recorded in the ADR
     * PR-5 addendum.)
     *
     * @throws DomainProviderException on the first provider failure — the
     *                                 caller (RES-6 destroy) runs before the affiliate soft-delete
     *                                 so a failure aborts the whole delete cleanly.
     */
    public function removeAllForDelete(Affiliate $affiliate): void
    {
        $domains = $affiliate->customDomains()->whereNotNull('provider_ref')->get();

        foreach ($domains as $domain) {
            $this->provider->detach($domain->provider_ref);
        }

        $affiliate->customDomains()->forceDelete();

        $this->propagateChange();
    }

    /**
     * Addendum section G: a row stuck `pending` past
     * `services.vercel.stuck_pending_days` (the affiliate never set their
     * DNS). Detach at the provider, mark `failed`, and clear
     * `provider_ref` so the daily poll stops touching it — the affiliate
     * can re-add the hostname cleanly.
     *
     * @throws DomainProviderException
     */
    public function tearDownStuckPending(AffiliateDomain $domain): void
    {
        if ($domain->provider_ref !== null) {
            $this->provider->detach($domain->provider_ref);
        }

        $affiliate = $domain->affiliate;

        $domain->forceFill([
            'status' => AffiliateDomainStatus::Failed,
            'provider_ref' => null,
            'verification' => null,
            'is_primary' => false,
            'last_checked_at' => now(),
            'last_error' => 'Domain setup was not completed in time. Add the domain again once the DNS records are in place.',
        ])->save();

        if ($affiliate !== null) {
            $this->failoverPrimary($affiliate);
        }

        $this->propagateChange();
    }

    /**
     * ADR-078 PR-2: a domain state change must reach two caches the
     * 60s/15s TTLs would otherwise carry stale for up to a minute —
     * this backend's active-origin CORS allow-list (ADR-078 PR-1) and
     * the storefront's Next.js Data Cache (a suspended/removed host must
     * stop resolving its brand; a just-verified one must start). Both
     * are best-effort: `NextRevalidation::purge()` is a no-op when the
     * revalidate webhook is unconfigured and a deduplicated queued job
     * otherwise; the cache forget is a single store delete. On failure
     * the TTLs are the backstop. No cross-Vercel-instance `proxy.ts`
     * invalidation is attempted — its TTL (now 15s) is the floor.
     */
    private function propagateChange(): void
    {
        $this->corsOrigins->flush();
        NextRevalidation::purge();
    }

    /**
     * Map a provider state onto the row (the single write path).
     * `is_primary` is only ever *granted* here (first domain to go
     * active), never revoked — revocation is `failoverPrimary()` /
     * `setPrimary()` / `suspendAll()`.
     */
    private function applyState(AffiliateDomain $domain, DomainProviderState $state): void
    {
        $affiliate = $domain->affiliate;

        $status = match (true) {
            $state->missing => AffiliateDomainStatus::Failed,
            $state->verified => AffiliateDomainStatus::Active,
            default => AffiliateDomainStatus::Pending,
        };

        $becameActive = $status === AffiliateDomainStatus::Active
            && $domain->status !== AffiliateDomainStatus::Active;

        $domain->forceFill([
            'provider_ref' => $state->providerRef,
            'verification' => $state->verification,
            'status' => $status,
            'last_checked_at' => now(),
            'verified_at' => $status === AffiliateDomainStatus::Active
                ? ($domain->verified_at ?? now())
                : null,
            'last_error' => null,
        ]);

        // First domain to reach active auto-becomes the canonical primary
        // (addendum section E).
        if ($becameActive && $affiliate !== null && ! $this->hasActivePrimary($affiliate, $domain)) {
            $domain->is_primary = true;
        }

        $domain->save();

        // A primary that just failed hands off to another active domain.
        if ($status !== AffiliateDomainStatus::Active && $domain->is_primary && $affiliate !== null) {
            $domain->forceFill(['is_primary' => false])->save();
            $this->failoverPrimary($affiliate);
        }
    }

    private function failoverPrimary(Affiliate $affiliate): void
    {
        if ($affiliate->customDomains()->where('is_primary', true)->exists()) {
            return;
        }

        $next = $affiliate->customDomains()
            ->where('status', AffiliateDomainStatus::Active)
            ->orderBy('verified_at')
            ->orderBy('id')
            ->first();

        $next?->forceFill(['is_primary' => true])->save();
    }

    private function hasActivePrimary(Affiliate $affiliate, AffiliateDomain $ignore): bool
    {
        return $affiliate->customDomains()
            ->where('is_primary', true)
            ->whereKeyNot($ignore->getKey())
            ->where('status', AffiliateDomainStatus::Active)
            ->exists();
    }

    private function assertManageable(Affiliate $affiliate): void
    {
        // A lapsed *subscription* still manages domains (domain ≠
        // subscription — addendum section D); only a not-yet-activated or
        // deactivated affiliate is blocked, and the controller/route
        // guards those. This guard is the backstop.
        if ($affiliate->status !== 'active') {
            throw ValidationException::withMessages([
                'hostname' => ['Your account is not active. Contact support.'],
            ]);
        }
    }

    private function assertHostnameAllowed(string $hostname): void
    {
        $reserved = $hostname === 'pekangame.space'
            || str_ends_with($hostname, '.pekangame.space')
            || $hostname === strtolower((string) config('services.vercel.connect_cname'))
            || in_array($hostname, (array) config('services.storefront.primary_hosts'), true);

        if ($reserved) {
            throw ValidationException::withMessages([
                'hostname' => ['That domain cannot be used.'],
            ]);
        }
    }

    /**
     * Lower-case, trim, strip a leading scheme / trailing path or port,
     * and reject anything that is not a bare hostname. The FormRequest
     * validates shape too; this is the service-level backstop.
     */
    private function normalise(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));
        $hostname = preg_replace('#^https?://#', '', $hostname) ?? $hostname;
        $hostname = explode('/', $hostname, 2)[0];
        $hostname = explode(':', $hostname, 2)[0];

        if ($hostname === ''
            || filter_var($hostname, FILTER_VALIDATE_IP) !== false
            || ! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $hostname)) {
            throw ValidationException::withMessages([
                'hostname' => ['Enter a valid domain, e.g. shop.yourbrand.com.'],
            ]);
        }

        return $hostname;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            || str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
