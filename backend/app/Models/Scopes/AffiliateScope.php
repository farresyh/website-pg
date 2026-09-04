<?php

namespace App\Models\Scopes;

use App\Support\CurrentAffiliate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * ADR-057: constrains every BelongsToAffiliate model to the current
 * tenant. Keyed off CurrentAffiliate (guard-derived, ADR-058), never a
 * request parameter or a per-query `where`.
 *
 *  - CurrentAffiliate not active  → no constraint (admin, console, queue,
 *                                  storefront guest).
 *  - active + id                 → WHERE <table>.affiliate_id = <id>.
 *  - active + no id              → WHERE 1 = 0. Deny-by-default: a
 *                                  affiliate session with no resolvable
 *                                  tenant sees ZERO rows, never all
 *                                  (ADR-057 decision 3 — fail closed).
 *  - runWithout() bypass active  → no constraint (ADR-057 decision 5).
 */
class AffiliateScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $current = app(CurrentAffiliate::class);

        if (! $current->isActive() || $current->isBypassed()) {
            return;
        }

        $id = $current->id();

        if ($id === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('affiliate_id'), $id);
    }
}
