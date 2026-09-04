<?php

namespace App\Models\Concerns;

use App\Models\Affiliate;
use App\Models\Scopes\AffiliateScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-057: tenant isolation. Any model that carries a `affiliate_id` and
 * is ever read under the affiliate guard (ADR-058) uses this. Booting
 * AffiliateScope as a global scope makes the SAFE path (only-my-rows) the
 * default — cross-tenant access is something you write on purpose via
 * withoutAffiliateScope() or CurrentAffiliate::runWithout().
 *
 * NOT for `ledger_entries`: that keys tenant off polymorphic
 * owner_type/owner_id, has no `affiliate_id` column, and is read through
 * AffiliateEarningsService (ADR-059) which filters owner_* explicitly.
 * See ADR-057's consequence note.
 */
trait BelongsToAffiliate
{
    public static function bootBelongsToAffiliate(): void
    {
        static::addGlobalScope(new AffiliateScope);
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /**
     * ADR-057 decision 5: per-query escape hatch for a deliberate
     * cross-tenant read. There are none planned; it exists so a future
     * need never tempts a raw query that bypasses the trait entirely.
     */
    public static function withoutAffiliateScope(): Builder
    {
        return static::withoutGlobalScope(AffiliateScope::class);
    }
}
