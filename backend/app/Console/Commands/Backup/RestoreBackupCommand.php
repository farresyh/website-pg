<?php

namespace App\Console\Commands\Backup;

use App\Models\BackupRun;
use App\Services\Backup\BackupRestorer;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * ADR-039 decision 5 + 2026-10-14 addendum (item 97): restore is CLI-only.
 * Restores a backup archive into an existing, empty database through the
 * same BackupRestorer the nightly restore test proves, then runs the same
 * verification. It never writes over a database that has tables.
 *
 *   php artisan app:restore-backup --run=44 --database=defaultdb_recovered
 *   php artisan app:restore-backup --path=PekanGame/2026-10-14-02-07-09.zip --database=defaultdb
 *   php artisan app:restore-backup --file=/home/forge/archive.zip --database=defaultdb
 */
class RestoreBackupCommand extends Command
{
    protected $signature = 'app:restore-backup
        {--run= : backup_runs id (archive read from that run\'s disk)}
        {--path= : archive path on the backup disk, for when backup_runs is gone}
        {--file= : local archive (.zip)}
        {--database= : target database name (MySQL) or file path (sqlite) — must exist and be empty}';

    protected $description = 'Restore a backup archive into an empty database (ADR-039).';

    public function handle(BackupRestorer $restorer): int
    {
        $sources = array_filter([$this->option('run'), $this->option('path'), $this->option('file')]);
        $database = (string) $this->option('database');

        if (count($sources) !== 1 || $database === '') {
            $this->error('Give exactly one of --run, --path or --file, plus --database.');

            return self::FAILURE;
        }

        $workDir = $restorer->makeWorkDir('restore-cli-');

        try {
            $zipPath = $this->option('file') ?: $this->downloadArchive($restorer, $workDir);
            $this->info('Extracting archive…');
            $dumpPath = $restorer->extractDump($zipPath, $workDir);

            $this->info("Restoring into [{$database}]…");
            $restorer->restore($dumpPath, $database);

            $result = $restorer->verify($database);
            $this->table(['check', 'value'], collect($result['details'])->map(fn ($v, $k) => [$k, json_encode($v)])->values()->all());

            if (! $result['passed']) {
                $this->error('Restore finished but verification failed — do not point the app at this database.');

                return self::FAILURE;
            }

            $this->info('Restore verified.');

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

            if (! $run->path) {
                throw new RuntimeException("backup run #{$runId} has no archive");
            }

            if (! $run->restore_test_passed) {
                $this->warn("Backup run #{$runId} did not pass its nightly restore test — check the result below carefully.");
            }

            [$disk, $path] = [$run->disk, $run->path];
        } else {
            [$disk, $path] = [config('filesystems.backup_disk'), (string) $this->option('path')];
        }

        $this->info("Downloading [{$path}] from [{$disk}]…");

        return $restorer->download($disk, $path, $workDir);
    }
}
