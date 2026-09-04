<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-058 58b (RES-4): audit row for one admin impersonation of a
 * affiliate portal session. Opened (`started_at`) when the admin mints
 * the short-lived `affiliate`-guard token, closed (`ended_at` +
 * `ended_reason`) when the session ends or the token is revoked.
 */
class AffiliateImpersonationSession extends Model
{
    protected $fillable = [
        'affiliate_id',
        'admin_user_id',
        'affiliate_user_id',
        'personal_access_token_id',
        'reason',
        'ip',
        'started_at',
        'ended_at',
        'ended_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function affiliateUser(): BelongsTo
    {
        return $this->belongsTo(AffiliateUser::class);
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }
}
