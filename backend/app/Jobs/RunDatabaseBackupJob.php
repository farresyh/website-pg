<?php

namespace App\Jobs;

use App\Models\BackupRun;
use App\Services\Backup\BackupFailureAlerter;
use App\Services\Backup\BackupRestorer;
use App\Services\Backup\BackupRestoreTester;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * ADR-039 decisions 2/8/9: the one job both the daily `Schedule::call()`
 * entry (routes/console.php, via `RunBackupCommand`) and the manual
 * "Backup Now" admin action (`Middleware\BackupController::store`) go
 * through — runs `backup:run --only-db`, locates the resulting archive,
 * restore-tests it (decision 8), and records the outcome onto the given
 * `BackupRun` row. Same "thin trigger, real work off-thread" shape as
 * `SyncSupplierPricesJob` (ADR-014/ADR-015) — never inline on the
 * request thread, this can genuinely take a while (dump + a full
 * restore-into-a-throwaway-database cycle).
 */
final class RunDatabaseBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public function __construct(public readonly BackupRun $run)
    {
        $this->onQueue('backups');
    }

    public function handle(BackupRestoreTester $tester, BackupRestorer $restorer, BackupFailureAlerter $alerter): void
    {
        self::sweepLeftoverExtracts();

        $disk = config('filesystems.backup_disk');

        $this->run->update(['status' => 'running', 'disk' => $disk, 'started_at' => now()]);

        $exitCode = Artisan::call('backup:run', ['--only-db' => true]);
        $output = Artisan::output();

        if ($exitCode !== 0) {
            $message = trim($output) !== '' ? trim($output) : "backup:run exited with code {$exitCode}";
            $this->failRun($message);
            // spatie's own BackupHasFailed event (if the underlying job
            // threw) already triggers LogAndAlertBackupFailure — this
            // covers the case where backup:run exits non-zero without
            // that event firing.
            $alerter->alert('Backup run failed', $message);

            return;
        }

        $backupPath = $this->latestBackupFile($disk, $this->run->started_at);

        if ($backupPath === null) {
            $message = 'backup:run reported success but no new archive was found on disk.';
            $this->failRun($message);
            $alerter->alert('Backup run failed', $message);

            return;
        }

        $sizeBytes = Storage::disk($disk)->size($backupPath);
        $restoreResult = $this->restoreTest($tester, $restorer, $disk, $backupPath);

        $this->run->update([
            'status' => $restoreResult['passed'] ? 'success' : 'failed',
            'path' => $backupPath,
            'size_bytes' => $sizeBytes,
            'restore_test_passed' => $restoreResult['passed'],
            'restore_test_details' => $restoreResult['details'],
            'error_message' => $restoreResult['passed'] ? null : ($restoreResult['details']['error'] ?? 'restore test failed'),
            'finished_at' => now(),
        ]);

        if (! $restoreResult['passed']) {
            $alerter->alert(
                'Backup restore-test failed',
                "Archive [{$backupPath}] was created but failed its restore test: ".
                    ($restoreResult['details']['error'] ?? json_encode($restoreResult['details'])),
            );
        }
    }

    private function failRun(string $message): void
    {
        $this->run->update([
            'status' => 'failed',
            'error_message' => $message,
            'finished_at' => now(),
        ]);
    }

    /**
     * spatie's backup:run doesn't return the archive path it just wrote
     * — locate it by taking the newest file on the disk created at or
     * after this run started.
     */
    private function latestBackupFile(string $disk, Carbon $startedAt): ?string
    {
        $appFolder = config('backup.backup.name');

        $candidates = collect(Storage::disk($disk)->allFiles($appFolder))
            ->filter(fn (string $path) => str_ends_with($path, '.zip'))
            ->filter(fn (string $path) => Storage::disk($disk)->lastModified($path) >= $startedAt->clone()->subMinute()->timestamp)
            ->sortByDesc(fn (string $path) => Storage::disk($disk)->lastModified($path));

        return $candidates->first();
    }

    /**
     * Item 97: a run killed by the worker timeout (2026-09-26..30) never
     * reached handle()'s own status update, so its row stayed `running`
     * and nobody was told. The worker calls this before killing the job.
     */
    public function failed(?Throwable $exception): void
    {
        $message = 'backup job died: '.($exception?->getMessage() ?? 'unknown error');
        $this->failRun($message);
        app(BackupFailureAlerter::class)->alert('Backup run failed', $message);
    }

    /**
     * A killed run's `finally` never runs, so its decrypted plaintext dump
     * (customer PII) stayed on disk. Each run clears what earlier runs left.
     * ponytail: safe only because supervisor-backups runs one job at a time
     * (maxProcesses 1); the operator's `app:restore-backup` uses its own
     * `restore-cli-` prefix, never swept here.
     */
    public static function sweepLeftoverExtracts(): void
    {
        $restorer = app(BackupRestorer::class);

        foreach (glob(storage_path('app/backup-temp/restore-extract-*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $restorer->deleteDirectory($dir);
        }
    }

    /**
     * @return array{passed: bool, details: array<string, mixed>}
     */
    private function restoreTest(BackupRestoreTester $tester, BackupRestorer $restorer, string $disk, string $backupPath): array
    {
        $workDir = $restorer->makeWorkDir('restore-extract-');

        try {
            $dumpPath = $restorer->extractDump($restorer->download($disk, $backupPath, $workDir), $workDir);

            return $tester->test($dumpPath);
        } catch (RuntimeException $e) {
            return ['passed' => false, 'details' => ['error' => $e->getMessage()]];
        } finally {
            $restorer->deleteDirectory($workDir);
        }
    }
}
