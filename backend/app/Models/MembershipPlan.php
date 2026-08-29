<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-027's 2026-08-29 addendum, decision 15: exactly two rows,
 * edit-only from /admin/membership — no create/delete endpoint exists
 * or is planned, since decision 4's anchor/decoy pricing structurally
 * requires exactly two tiers live together.
 */
class MembershipPlan extends Model
{
    protected $fillable = [
        'name',
        'fee_sen',
        'quota_sen',
        'discount_percent',
    ];

    protected $casts = [
        'fee_sen' => 'integer',
        'quota_sen' => 'integer',
        'discount_percent' => 'decimal:2',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
