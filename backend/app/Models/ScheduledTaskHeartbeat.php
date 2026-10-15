<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-122: the last time the scheduler ran a task successfully, keyed by
 * the task's `name()` in TaskSchedule.
 */
class ScheduledTaskHeartbeat extends Model
{
    protected $fillable = [
        'name',
        'last_ok_at',
        'last_runtime_seconds',
        'last_failed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'last_ok_at' => 'datetime',
            'last_failed_at' => 'datetime',
        ];
    }
}
