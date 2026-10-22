<?php

namespace App\Services\Backup;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * ADR-039 2026-10-14 addendum (item 97): the one restore path. The nightly
 * restore test (BackupRestoreTester) and the operator's
 * `app:restore-backup` command both go through here, so every nightly test
 * proves the path a real disaster restore would use.
 *
 * Restores only into an existing, empty database (a MySQL database name on
 * the default connection's server, or a sqlite file path) — never over
 * live data (ADR-039 decision 5's deliberate friction).
 */
final class BackupRestorer
{
    private const CONNECTION = 'backup_restore_target';

    public function makeWorkDir(string $prefix): string
    {
        $dir = storage_path('app/backup-temp/'.$prefix.uniqid('', true));
        mkdir($dir, 0700, true);

        return $dir;
    }

    public function download(string $disk, string $path, string $workDir): string
    {
        $local = $workDir.'/archive.zip';
        $stream = Storage::disk($disk)->readStream($path);

        if ($stream === null) {
            throw new RuntimeException("backup archive [{$path}] not found on disk [{$disk}]");
        }

        $out = fopen($local, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);

        return $local;
    }

    /** Decrypts and extracts the archive; returns the SQL dump's path. */
    public function extractDump(string $zipPath, string $workDir): string
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('could not open backup archive');
        }

        if ($password = config('backup.backup.password')) {
            $zip->setPassword($password);
        }

        $extracted = $zip->extractTo($workDir);
        $zip->close();

        $dumps = glob($workDir.'/db-dumps/*') ?: [];

        if (! $extracted || $dumps === []) {
            throw new RuntimeException('no db dump found inside backup archive (wrong BACKUP_ARCHIVE_PASSWORD?)');
        }

        return $dumps[0];
    }

    /**
     * ADR-039 2026-10-22 addendum: decrypts an OpenWA archive (see
     * OpenWaBackup) into the work dir. The nightly restore test and the
     * operator's `app:restore-openwa-backup` both come through here.
     *
     * @return array{tar: string, env: ?string}
     */
    public function extractOpenWa(string $zipPath, string $workDir): array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('could not open OpenWA backup archive');
        }

        if ($password = config('backup.backup.password')) {
            $zip->setPassword($password);
        }

        $extracted = $zip->extractTo($workDir);
        $zip->close();

        $tars = glob($workDir.'/openwa-backup-*.tar.gz') ?: [];

        if (! $extracted || count($tars) !== 1) {
            throw new RuntimeException('no OpenWA archive found inside the backup zip (wrong BACKUP_ARCHIVE_PASSWORD?)');
        }

        return ['tar' => $tars[0], 'env' => is_file($workDir.'/openwa.env') ? $workDir.'/openwa.env' : null];
    }

    /**
     * A killed run's `finally` never runs, so its decrypted plaintext (a
     * customer-PII dump, or OpenWA's session keys) stays on disk. Each backup
     * run clears what earlier runs left. ponytail: safe only because
     * supervisor-backups runs one job at a time (maxProcesses 1); the
     * operator commands use their own `restore-cli-` prefix, never swept.
     */
    public function sweepJobLeftovers(): void
    {
        foreach (['restore-extract-', 'openwa-backup-'] as $prefix) {
            foreach (glob(storage_path('app/backup-temp/'.$prefix.'*'), GLOB_ONLYDIR) ?: [] as $dir) {
                $this->deleteDirectory($dir);
            }
        }
    }

    public function restore(string $dumpPath, string $database): void
    {
        $tables = $this->tables($database);

        if ($tables !== []) {
            throw new RuntimeException("target [{$database}] is not empty (".count($tables).' tables) — restore only into an empty database');
        }

        $this->isSqlite() ? $this->restoreSqlite($dumpPath, $database) : $this->restoreMysql($dumpPath, $database);
    }

    /**
     * Tables and views in a database on the default connection's server
     * (the default database itself when null).
     *
     * @return list<string>
     */
    public function tables(?string $database = null): array
    {
        $sql = $this->isSqlite()
            ? "SELECT name FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%'"
            : 'SHOW TABLES';

        $connection = $database === null ? DB::connection() : $this->target($database);

        try {
            $names = array_map(fn ($row) => (string) array_values((array) $row)[0], $connection->select($sql));
        } finally {
            if ($database !== null) {
                DB::purge(self::CONNECTION);
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Sanity check after a restore: tables exist, every migration this
     * codebase knows is recorded as run (what `migrate:status` reports,
     * read directly rather than scraped from its output), and — when the
     * caller knows the source's table list — no table is missing. A dump cut
     * short after the `migrations` table would otherwise pass.
     *
     * @param  list<string>|null  $expectedTables
     * @return array{passed: bool, details: array<string, mixed>}
     */
    public function verify(string $database, ?array $expectedTables = null): array
    {
        try {
            $restored = $this->tables($database);
            $missing = $expectedTables === null ? [] : array_values(array_diff($expectedTables, $restored));

            $migrator = app('migrator');
            $known = array_keys($migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths())));
            $ran = in_array('migrations', $restored, true)
                ? $this->target($database)->table('migrations')->pluck('migration')->all()
                : [];
            $pending = array_values(array_diff($known, $ran));

            return [
                'passed' => $missing === [] && $ran !== [] && $pending === [],
                'details' => [
                    'table_count' => count($restored),
                    'missing_tables' => $missing,
                    'migrations_ran' => count($ran),
                    'pending_migrations' => $pending,
                ],
            ];
        } catch (Throwable $e) {
            return ['passed' => false, 'details' => ['error' => 'restore verification failed: '.$e->getMessage()]];
        } finally {
            DB::purge(self::CONNECTION);
            config(['database.connections.'.self::CONNECTION => null]);
        }
    }

    public function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
            $path = "{$dir}/{$file}";
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function restoreSqlite(string $dumpPath, string $database): void
    {
        $process = new Process(['sqlite3', $database]);
        $process->setInput(fopen($dumpPath, 'rb'));
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('sqlite restore command failed: '.$process->getErrorOutput());
        }
    }

    private function restoreMysql(string $dumpPath, string $database): void
    {
        $config = $this->baseConfig();
        $credentials = tempnam(sys_get_temp_dir(), 'restore-cnf-');
        chmod($credentials, 0600);
        file_put_contents($credentials, sprintf(
            "[client]\nuser=\"%s\"\npassword=\"%s\"\nhost=\"%s\"\nport=%d\n",
            addslashes((string) $config['username']),
            addslashes((string) ($config['password'] ?? '')),
            addslashes((string) $config['host']),
            (int) ($config['port'] ?? 3306),
        ));

        try {
            // --defaults-extra-file keeps the password out of argv (`ps`).
            $cleanDump = $this->withoutDefiners($dumpPath);
            $process = new Process(['mysql', '--defaults-extra-file='.$credentials, $database]);
            $process->setInput(fopen($cleanDump, 'rb'));
            $process->setTimeout(null);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('mysql restore command failed: '.$process->getErrorOutput());
            }
        } finally {
            @unlink($credentials);
            @unlink($dumpPath.'.restore');
        }
    }

    /**
     * mysqldump keeps each view's DEFINER, and creating an object owned by
     * another user needs SET_ANY_DEFINER, which a managed-database user
     * never has (same class as the 2026-09-14 GTID fix). Dropping the clause
     * makes the restoring user the owner. INSERT lines are passed through
     * untouched so customer data can never be rewritten.
     *
     * Written beside the dump (same private work dir) and streamed to
     * mysql as a file: Process feeds an iterator input one chunk per poll,
     * far too slow for a real dump.
     */
    private function withoutDefiners(string $dumpPath): string
    {
        $out = $dumpPath.'.restore';
        $in = fopen($dumpPath, 'rb');
        $write = fopen($out, 'wb');

        try {
            while (($line = fgets($in)) !== false) {
                fwrite($write, str_starts_with($line, 'INSERT ')
                    ? $line
                    : preg_replace('/DEFINER=`(?:[^`]|``)*`@`(?:[^`]|``)*`\s*/', '', $line));
            }
        } finally {
            fclose($in);
            fclose($write);
        }

        return $out;
    }

    private function target(string $database): Connection
    {
        config(['database.connections.'.self::CONNECTION => array_merge($this->baseConfig(), ['database' => $database])]);
        DB::purge(self::CONNECTION);

        return DB::connection(self::CONNECTION);
    }

    /** @return array<string, mixed> */
    private function baseConfig(): array
    {
        return config('database.connections.'.config('database.default'));
    }

    private function isSqlite(): bool
    {
        return $this->baseConfig()['driver'] === 'sqlite';
    }
}
