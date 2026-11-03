<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-136 decision 17: one approved piece of support knowledge. Only rows
 * with status approved are ever given to the assistant (PR 3), filtered by
 * brand (null affiliate = every brand).
 */
class CsKnowledgeEntry extends Model
{
    public const STATUS_APPROVED = 'approved';

    public const STATUS_ARCHIVED = 'archived';

    /** Decision 8's categories. */
    public const CATEGORIES = [
        'how_to_buy',
        'price_package',
        'payment',
        'order_status',
        'order_failed',
        'game_id_server',
        'voucher_promo',
        'partner',
        'other',
    ];

    protected $fillable = [
        'category',
        'questions',
        'answer',
        'affiliate_id',
        'game_id',
        'valid_until',
        'status',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'valid_until' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'use_count' => 'integer',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class)->withTrashed();
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by');
    }
}
