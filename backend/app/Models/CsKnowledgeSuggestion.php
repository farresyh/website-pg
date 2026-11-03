<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-136 decision 10: a masked customer question and the staff answer that
 * followed, proposed each night by CsKnowledgeSuggester. The model never
 * reads this table (decision 17); an admin turns it into an entry.
 */
class CsKnowledgeSuggestion extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_MERGED = 'merged';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_IGNORED = 'ignored';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_MERGED,
        self::STATUS_REJECTED,
        self::STATUS_IGNORED,
    ];

    protected $fillable = [
        'chat_hash',
        'first_message_id',
        'question',
        'answer',
        'ai_question',
        'ai_answer',
        'ai_category',
        'ai_entry_id',
        'ai_cost_usd_micros',
        'ai_written_at',
        'order_numbers',
        'affiliate_id',
        'asked_at',
        'status',
        'cs_knowledge_entry_id',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $hidden = ['chat_hash'];

    protected function casts(): array
    {
        return [
            'order_numbers' => 'array',
            'asked_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'ai_written_at' => 'datetime',
            'ai_entry_id' => 'integer',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class)->withTrashed();
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(CsKnowledgeEntry::class, 'cs_knowledge_entry_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }
}
