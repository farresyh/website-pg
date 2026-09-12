<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ADR-073 decision 1: the fee-less prepaid-wallet tier ladder a
 * `Reseller` account is assigned to. `markup_percent` over supplier
 * `cost_price`, same math as `AffiliateMembershipTier` but no
 * `monthly_fee_sen` / lapse state machine — assignment is a direct FK
 * swap on `Reseller.reseller_tier_id`.
 *
 * Soft-deletes: `resellers.reseller_tier_id` is restrict-on-delete at the
 * DB, so a tier any reseller is currently assigned to can't be
 * hard-deleted anyway — soft-delete mirrors `AffiliateMembershipTier`'s
 * own precedent for a consistent admin-CRUD "delete" semantic.
 *
 * ADR-091: `show_on_price_list` opts a tier into the public Reseller
 * Price List page (`PublicResellerPriceListController`) — at most 3
 * `true` at once (enforced in Store/UpdateResellerTierRequest, not a DB
 * constraint), display order is the existing `sort_order`.
 */
class ResellerTier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'markup_percent',
        'is_active',
        'sort_order',
        'show_on_price_list',
    ];

    protected $casts = [
        'markup_percent' => 'decimal:2',
        'is_active' => 'boolean',
        'show_on_price_list' => 'boolean',
    ];

    public function resellers(): HasMany
    {
        return $this->hasMany(Reseller::class);
    }
}
