<?php

namespace App\Models;

use App\Jobs\RunDatabaseBackupJob;
use App\Jobs\RunOpenWaBackupJob;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-039 decision 9: one row per backup run — status
 * (queued/running/success/failed) plus decision 8's restore-test result
 * is what `/middleware/backups`'s history table renders. The 2026-10-22
 * addendum adds `source`: the Laravel database or the OpenWA gateway's data.
 */
class BackupRun extends Model
{
    public const SOURCE_DATABASE = 'database';

    public const SOURCE_OPENWA = 'openwa';

    public const SOURCES = [self::SOURCE_DATABASE, self::SOURCE_OPENWA];

    protected $fillable = [
        'source',
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

    protected $attributes = [
        'source' => self::SOURCE_DATABASE,
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

    /**
     * The one way a run starts: the schedule (`app:run-backup`) and the
     * admin's "Backup Now" both come through here, so a source always gets
     * its own job.
     */
    public static function queue(string $source, string $triggeredBy): self
    {
        $run = self::query()->create(['source' => $source, 'status' => 'queued', 'triggered_by' => $triggeredBy]);

        $source === self::SOURCE_OPENWA ? RunOpenWaBackupJob::dispatch($run) : RunDatabaseBackupJob::dispatch($run);

        return $run;
    }

    public function markFailed(string $message): void
    {
        $this->update(['status' => 'failed', 'error_message' => $message, 'finished_at' => now()]);
    }
}
