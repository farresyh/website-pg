<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-039 decision 9: one row per `app:run-backup` execution — status
 * (queued/running/success/failed) plus decision 8's restore-test result
 * is what `/middleware/backups`'s history table renders.
 */
class BackupRun extends Model
{
    protected $fillable = [
        'status',
        'triggered_by',
        'disk',
        'path',
        'size_bytes',
        'restore_test_passed',
        'restore_test_details',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'restore_test_passed' => 'boolean',
            'restore_test_details' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
