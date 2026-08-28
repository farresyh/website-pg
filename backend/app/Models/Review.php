<?php

namespace App\Models;

use App\Services\Review\ReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-053 (REV-1..5). `game`/`package` are never duplicated onto this
 * table — read live via `order.game`/`order.package` (decision 5).
 */
class Review extends Model
{
    protected $fillable = [
        'order_id',
        'rating',
        'comment',
        'status',
    ];

    protected $casts = [
        'rating' => 'integer',
        'status' => ReviewStatus::class,
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
