<?php

namespace App\Support;

/**
 * ADR-057: the request-scoped "which affiliate tenant is this" resolver.
 * Bound as a `scoped` binding in AppServiceProvider (reset between HTTP
 * requests and between queue jobs — never a process-lifetime singleton
 * that could leak one request's tenant into the next).
 *
 * The affiliate-guard middleware (ADR-058) calls activate() once per
 * request from the authenticated `affiliate_user`'s `affiliate_id` — NEVER
 * from a request parameter. AffiliateScope reads this to constrain every
 * BelongsToAffiliate model.
 *
 * Three states matter:
 *  - not active            → no tenant context: the admin panel, the
 *                            console, queue jobs, storefront guest
 *                            checkout. AffiliateScope adds no constraint.
 *  - active with an id     → a real affiliate session. Scope to that id.
 *  - active without an id  → an affiliate session with no resolvable
 *                            tenant. A bug. AffiliateScope fails CLOSED
 *                            (zero rows), never open — ADR-057 decision 3.
 */
class CurrentAffiliate
{
    private bool $active = false;

    private ?int $id = null;

    private bool $bypassed = false;

    /**
     * Enter an affiliate-tenant context. `$affiliateId` is null only when a
     * affiliate session exists but the tenant could not be resolved —
     * AffiliateScope then denies by default.
     */
    public function activate(?int $affiliateId): void
    {
        $this->active = true;
        $this->id = $affiliateId;
    }

    public function deactivate(): void
    {
        $this->active = false;
        $this->id = null;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function isBypassed(): bool
    {
        return $this->bypassed;
    }

    /**
     * ADR-057 decision 5: the escape hatch for a deliberate cross-tenant
     * read under an affiliate context (none planned — it exists so a
     * future need never tempts a raw query that bypasses the trait
     * entirely). Nesting-safe: restores the previous state on exit.
     */
    public function runWithout(callable $callback): mixed
    {
        $previous = $this->bypassed;
        $this->bypassed = true;

        try {
            return $callback();
        } finally {
            $this->bypassed = $previous;
        }
    }
}
