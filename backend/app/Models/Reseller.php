<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRD §8: a branded storefront owner. The platform owner IS the first
 * row here (markup_pct=0), not a separate concept — see the
 * create_resellers_table migration's doc comment. No `balance` column:
 * balance is always derived from LedgerEntry (ADR-002).
 */
class Reseller extends Model
{
    protected $fillable = [
        'business_name',
        'contact_name',
        'email',
        'phone',
        'markup_pct',
        'max_markup_pct',
        'domains',
        'status',
        'xendit_subaccount_id',
        'notes',
    ];

    protected $casts = [
        'markup_pct' => 'decimal:2',
        'max_markup_pct' => 'decimal:2',
        'domains' => 'array',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
