<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ADR-068 2026-10-13 addendum: one renewal-reminder email for one paid-through period. */
class MembershipReminder extends Model
{
    public const KIND_BEFORE = 'before';

    public const KIND_AFTER = 'after';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = ['membership_id', 'kind', 'expires_at', 'status', 'attempts', 'error', 'sent_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'attempts' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }
}
