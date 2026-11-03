<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-136 decision 18: one answer the assistant drafted in Shadow for one
 * customer burst, never sent. The nightly judge pairs it with what staff
 * replied and grades it; a super_admin may overrule the grade.
 */
class CsBotDraft extends Model
{
    public const GRADES = ['same', 'partly', 'different', 'correct_handover', 'should_have_handed_over', 'unclear'];

    /** Grades that count as right for decision 27's accuracy. */
    public const RIGHT = ['same', 'partly', 'correct_handover'];

    protected $fillable = [
        'chat_hash', 'last_message_id', 'first_message_id', 'question', 'order_numbers', 'affiliate_id', 'asked_at',
        'model', 'action', 'reply', 'handover_reason', 'category', 'entry_ids', 'violations', 'error',
        'input_tokens', 'output_tokens', 'cost_usd_micros',
        'staff_answer', 'grade', 'grade_note', 'judge_model', 'judge_cost_usd_micros', 'judged_at', 'admin_grade', 'graded_by',
    ];

    protected $casts = [
        'order_numbers' => 'array',
        'entry_ids' => 'array',
        'violations' => 'array',
        'asked_at' => 'datetime',
        'judged_at' => 'datetime',
    ];

    /** The admin's grade wins over the judge's. */
    public function finalGrade(): ?string
    {
        return $this->admin_grade ?? $this->grade;
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class)->withTrashed();
    }
}
