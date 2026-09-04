<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PR-F build addendum decision 2: narrow, failure-only record of a
 * Reseller Bot command — an unrecognized command, or one that failed
 * catalog resolution/order placement. A successful order is never
 * logged here (it's already on `/admin/orders`). 7-day retention via
 * `app:prune-reseller-bot-command-logs`.
 */
class ResellerBotCommandLog extends Model
{
    protected $fillable = [
        'reseller_id',
        'whatsapp_group_id',
        'raw_command',
        'failure_reason',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }
}
