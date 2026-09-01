<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-058 58b (RES-4): audit row for one admin impersonation of a
 * reseller portal session. Opened (`started_at`) when the admin mints
 * the short-lived `reseller`-guard token, closed (`ended_at` +
 * `ended_reason`) when the session ends or the token is revoked.
 */
class ResellerImpersonationSession extends Model
{
    protected $fillable = [
        'reseller_id',
        'admin_user_id',
        'reseller_user_id',
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

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function resellerUser(): BelongsTo
    {
        return $this->belongsTo(ResellerUser::class);
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }
}
