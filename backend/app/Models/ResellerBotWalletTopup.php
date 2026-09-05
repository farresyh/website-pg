<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-076 PR-H decision 1 — one row per Bot-channel `.topupbaki`
 * request. See the migration's own doc comment for the full design
 * rationale (group-ambiguity + once-only-notification problems it
 * solves), which mirrors `ResellerBotOrderNotification`'s.
 */
class ResellerBotWalletTopup extends Model
{
    protected $fillable = [
        'reseller_id',
        'wallet_topup_attempt_id',
        'whatsapp_group_id',
        'notified_at',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(WalletTopupAttempt::class, 'wallet_topup_attempt_id');
    }
}
