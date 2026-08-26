<?php

namespace App\Jobs;

use App\Models\BackupRun;
use App\Services\Backup\BackupFailureAlerter;
use App\Services\Backup\BackupRestoreTester;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

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

    public function handle(BackupRestoreTester $tester, BackupFailureAlerter $alerter): void
    {
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
        $restoreResult = $this->restoreTest($tester, $disk, $backupPath);

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
     * @return array{passed: bool, details: array<string, mixed>}
     */
    private function restoreTest(BackupRestoreTester $tester, string $disk, string $backupPath): array
    {
        $tempDir = storage_path('app/backup-temp/restore-extract-'.uniqid('', true));
        mkdir($tempDir, 0755, true);

        try {
            $localZipPath = $tempDir.'/archive.zip';
            file_put_contents($localZipPath, Storage::disk($disk)->get($backupPath));

            $zip = new ZipArchive;

            if ($zip->open($localZipPath) !== true) {
                return ['passed' => false, 'details' => ['error' => 'could not open backup archive for restore-test']];
            }

            if ($password = config('backup.backup.password')) {
                $zip->setPassword($password);
            }

            $zip->extractTo($tempDir);
            $zip->close();

            $dumpFiles = glob($tempDir.'/db-dumps/*');

            if (empty($dumpFiles)) {
                return ['passed' => false, 'details' => ['error' => 'no db dump found inside backup archive']];
            }

            return $tester->test($dumpFiles[0]);
        } finally {
            $this->deleteDirectory($tempDir);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = "{$dir}/{$file}";
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
