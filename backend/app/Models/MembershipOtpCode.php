<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-027's 2026-08-29 addendum, decisions 23/26/27 — see the
 * migration's own comment for the full design intent. `code_hash` is
 * never exposed via `toArray()`/`toJson()`, same defense-in-depth as
 * `Supplier.api_config`.
 *
 * ADR-061 decision 5 (PR-B): `affiliate_id` scopes a code to the brand
 * that issued it — `OtpService::verify()` filters on it.
 */
class MembershipOtpCode extends Model
{
    protected $fillable = [
        'affiliate_id',
        'email',
        'code_hash',
        'expires_at',
        'consumed_at',
        'attempts',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
