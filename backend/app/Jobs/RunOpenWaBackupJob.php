<?php

namespace App\Jobs;

use App\Models\BackupRun;
use App\Services\Backup\BackupFailureAlerter;
use App\Services\Backup\BackupRestorer;
use App\Services\Backup\OpenWaBackup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * ADR-039 2026-10-22 addendum: the OpenWA source's run — create, upload,
 * restore-test, then prune, recorded on one BackupRun row. Same shape and
 * the same `backups` lane as RunDatabaseBackupJob (one job at a time), so a
 * failure in one source never touches the other's run.
 */
final class RunOpenWaBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public function __construct(public readonly BackupRun $run)
    {
        $this->onQueue('backups');
    }

    public function handle(OpenWaBackup $backup, BackupRestorer $restorer, BackupFailureAlerter $alerter): void
    {
        $restorer->sweepJobLeftovers();
        $disk = config('filesystems.backup_disk');
        $this->run->update(['status' => 'running', 'disk' => $disk, 'started_at' => now()]);
        $workDir = $restorer->makeWorkDir('openwa-backup-');

        try {
            $created = $backup->create($disk, $workDir.'/create');
            $this->run->update(['path' => $created['path'], 'size_bytes' => $created['size_bytes']]);
            $result = $backup->restoreTest($disk, $created['path'], $workDir.'/test');
        } catch (Throwable $e) {
            $this->run->markFailed($e->getMessage());
            $alerter->alert('OpenWA backup run failed', $e->getMessage());

            return;
        } finally {
            $restorer->deleteDirectory($workDir);
        }

        $this->run->update([
            'status' => $result['passed'] ? 'success' : 'failed',
            'restore_test_passed' => $result['passed'],
            'restore_test_details' => $result['details'],
            'error_message' => $result['passed'] ? null : ($result['details']['error'] ?? 'restore test failed'),
            'finished_at' => now(),
        ]);

        if (! $result['passed']) {
            $alerter->alert('OpenWA backup restore-test failed', "Archive [{$created['path']}] failed its restore test: ".json_encode($result['details']));

            return;
        }

        $backup->prune($disk);
    }

    public function failed(?Throwable $exception): void
    {
        $message = 'OpenWA backup job died: '.($exception?->getMessage() ?? 'unknown error');
        $this->run->markFailed($message);
        app(BackupFailureAlerter::class)->alert('OpenWA backup run failed', $message);
    }
}
