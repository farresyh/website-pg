<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ADR-126 decision 10: one progress update — the rank reached, who reported it and how. Never edited. */
class JokiProgressUpdate extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'rank', 'actor', 'channel'];

    protected $casts = ['rank' => 'array'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
