<?php

namespace App\Models\Concerns;

use App\Models\Reseller;
use App\Models\Scopes\ResellerScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-057: tenant isolation. Any model that carries a `reseller_id` and
 * is ever read under the reseller guard (ADR-058) uses this. Booting
 * ResellerScope as a global scope makes the SAFE path (only-my-rows) the
 * default — cross-tenant access is something you write on purpose via
 * withoutResellerScope() or CurrentReseller::runWithout().
 *
 * NOT for `ledger_entries`: that keys tenant off polymorphic
 * owner_type/owner_id, has no `reseller_id` column, and is read through
 * ResellerEarningsService (ADR-059) which filters owner_* explicitly.
 * See ADR-057's consequence note.
 */
trait BelongsToReseller
{
    public static function bootBelongsToReseller(): void
    {
        static::addGlobalScope(new ResellerScope);
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    /**
     * ADR-057 decision 5: per-query escape hatch for a deliberate
     * cross-tenant read. There are none planned; it exists so a future
     * need never tempts a raw query that bypasses the trait entirely.
     */
    public static function withoutResellerScope(): Builder
    {
        return static::withoutGlobalScope(ResellerScope::class);
    }
}
