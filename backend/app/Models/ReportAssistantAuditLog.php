<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-087 decision 8 — see the migration's own doc comment. Append-only
 * by convention (no application code ever updates or deletes a row);
 * `report-assistant:prune-audit-logs` is the only thing that deletes,
 * and only rows past the 90-day retention window.
 */
class ReportAssistantAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'admin_user_id',
        'question',
        'generated_sql',
        'row_count',
        'result_sample',
        'answer',
        'error',
        'created_at',
    ];

    protected $casts = [
        'result_sample' => 'array',
        'created_at' => 'datetime',
    ];

    public function adminUser()
    {
        return $this->belongsTo(AdminUser::class);
    }
}
