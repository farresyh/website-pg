<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-136 decision 33: the customer-support assistant's one settings row
 * (created by its migration), edited on the admin CS Assistant page by a
 * super_admin. Mode is Off, Shadow or, from PR 4, Live (decision 11).
 */
class CsAssistantSettings extends Model
{
    public const MODE_OFF = 'off';

    public const MODE_SHADOW = 'shadow';

    protected $fillable = [
        'mode', 'answer_model', 'judge_model', 'reassure_minutes', 'handover_minutes', 'manual_handover_minutes',
        'burst_wait_seconds', 'daily_cost_cap_sen', 'live_min_drafts', 'live_min_accuracy_pct', 'cost_cap_tripped_at', 'updated_by',
    ];

    protected $casts = [
        'reassure_minutes' => 'integer',
        'handover_minutes' => 'integer',
        'manual_handover_minutes' => 'integer',
        'burst_wait_seconds' => 'integer',
        'daily_cost_cap_sen' => 'integer',
        'live_min_drafts' => 'integer',
        'live_min_accuracy_pct' => 'integer',
        'cost_cap_tripped_at' => 'datetime',
    ];

    public static function current(): self
    {
        return self::query()->firstOrFail();
    }

    public function shadowing(): bool
    {
        return $this->mode === self::MODE_SHADOW;
    }
}
