<?php

namespace App\Console\Commands\Backup;

use App\Models\BackupRun;
use App\Services\Backup\BackupRestorer;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * ADR-039 2026-10-22 addendum: the disaster path for the OpenWA source.
 * Downloads and decrypts an archive through the same BackupRestorer the
 * nightly restore test uses, and leaves OpenWA's own archive plus its
 * `.env` in a private folder. The restore itself is OpenWA's
 * scripts/restore.sh on the target install (it refuses a live install
 * without --force):
 *
 *   php artisan app:restore-openwa-backup --run=120 --to=/home/forge/openwa-restore
 *   cd /home/forge/OpenWA && ./scripts/restore.sh /home/forge/openwa-restore/openwa-backup-….tar.gz
 *   cp /home/forge/openwa-restore/openwa.env /home/forge/OpenWA/.env
 */
class RestoreOpenWaBackupCommand extends Command
{
    protected $signature = 'app:restore-openwa-backup
        {--run= : backup_runs id of an openwa run}
        {--path= : archive path on the backup disk, for when backup_runs is gone}
        {--file= : local archive (.zip)}
        {--to= : empty or new folder to write the decrypted archive and .env into}';

    protected $description = 'Decrypt an OpenWA backup for scripts/restore.sh (ADR-039).';

    public function handle(BackupRestorer $restorer): int
    {
        $sources = array_filter([$this->option('run'), $this->option('path'), $this->option('file')]);
        $to = rtrim((string) $this->option('to'), '/');

        if (count($sources) !== 1 || $to === '') {
            $this->error('Give exactly one of --run, --path or --file, plus --to.');

            return self::FAILURE;
        }

        if (is_dir($to) && array_diff(scandir($to), ['.', '..']) !== []) {
            $this->error("[{$to}] is not empty.");

            return self::FAILURE;
        }

        $workDir = $restorer->makeWorkDir('restore-cli-');

        try {
            $zipPath = $this->option('file') ?: $this->downloadArchive($restorer, $workDir);
            $extracted = $restorer->extractOpenWa($zipPath, $workDir);

            if (! is_dir($to)) {
                mkdir($to, 0700, true);
            }
            rename($extracted['tar'], $to.'/'.basename($extracted['tar']));
            if ($extracted['env'] !== null) {
                rename($extracted['env'], $to.'/openwa.env');
            }

            $this->info("Decrypted into [{$to}] (it holds WhatsApp session keys: delete it after the restore).");
            $this->line('Next, on the OpenWA install: ./scripts/restore.sh '.$to.'/'.basename($extracted['tar']));

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $restorer->deleteDirectory($workDir);
        }
    }

    private function downloadArchive(BackupRestorer $restorer, string $workDir): string
    {
        if ($runId = $this->option('run')) {
            $run = BackupRun::query()->find($runId) ?? throw new RuntimeException("backup run #{$runId} not found");

            if ($run->source !== BackupRun::SOURCE_OPENWA || ! $run->path) {
                throw new RuntimeException("backup run #{$runId} is not an OpenWA run with an archive");
            }

            if (! $run->restore_test_passed) {
                $this->warn("Backup run #{$runId} did not pass its nightly restore test.");
            }

            return $restorer->download($run->disk, $run->path, $workDir);
        }

        return $restorer->download(config('filesystems.backup_disk'), (string) $this->option('path'), $workDir);
    }
}
