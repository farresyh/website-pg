<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-060 PR-6 (planning addendum decision 6 / Q17): one append-only row
 * per `affiliates.markup_pct` change. Never updated or deleted. Orders
 * already snapshot the effective rate per ORD-9, but a zero-order day
 * leaves no record and an admin-side change has no other trail — this
 * closes both. Mirrors `AffiliateTierChange`.
 *
 * `changed_by_id` is deliberately not a hard FK: the id space differs by
 * `source` (`affiliate_users` for a portal edit, `admin_users` for an
 * admin edit) and this audit row must outlive either user.
 */
class AffiliateMarkupChange extends Model
{
    /** Append-only — created_at is set manually, there is no updated_at. */
    public $timestamps = false;

    protected $fillable = [
        'affiliate_id',
        'old_pct',
        'new_pct',
        'source',
        'changed_by_id',
        'changed_by_label',
        'created_at',
    ];

    protected $casts = [
        'old_pct' => 'decimal:2',
        'new_pct' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
