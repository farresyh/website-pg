<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One model's answer to one case in one run. `model` stays hidden until the run is revealed. */
class CsEvalResult extends Model
{
    protected $fillable = [
        'cs_eval_run_id', 'cs_eval_case_id', 'model', 'blind_label', 'action', 'reply', 'handover_reason', 'category', 'entry_ids',
        'safety_passed', 'safety_notes', 'input_tokens', 'output_tokens', 'cost_usd_micros', 'latency_ms', 'error', 'picked', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_ids' => 'array',
            'safety_passed' => 'boolean',
            'safety_notes' => 'array',
            'picked' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function evalCase(): BelongsTo
    {
        return $this->belongsTo(CsEvalCase::class, 'cs_eval_case_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CsEvalRun::class, 'cs_eval_run_id');
    }
}
