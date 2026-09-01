<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * ADR-039 decision 8: every backup run is restore-tested automatically —
 * restore into a throwaway database, then a sanity check (row count > 0,
 * `migrate:status` clean). Driver-aware (sqlite locally today per
 * AGENTS.md's `DB_CONNECTION` default, real MySQL once ADR-020's host is
 * live) since this feature is deliberately host-agnostic — the
 * consequence-to-track on this ADR flags that the sqlite path here isn't
 * assumed to behave identically to a real MySQL restore.
 */
class BackupRestoreTester
{
    private const CONNECTION = 'backup_restore_test';

    /** @return array{passed: bool, details: array<string, mixed>} */
    public function test(string $dumpPath): array
    {
        return config('database.default') === 'sqlite'
            ? $this->testSqlite($dumpPath)
            : $this->testMysql($dumpPath);
    }

    /** @return array{passed: bool, details: array<string, mixed>} */
    private function testSqlite(string $dumpPath): array
    {
        $targetPath = storage_path('app/backup-temp/restore-test-'.uniqid('', true).'.sqlite');
        touch($targetPath);

        try {
            $restore = Process::fromShellCommandline(
                sprintf('sqlite3 %s < %s', escapeshellarg($targetPath), escapeshellarg($dumpPath)),
            );
            $restore->run();

            if (! $restore->isSuccessful()) {
                return $this->failed('sqlite restore command failed: '.$restore->getErrorOutput());
            }

            return $this->verify([
                'driver' => 'sqlite',
                'database' => $targetPath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        } finally {
            @unlink($targetPath);
        }
    }

    /** @return array{passed: bool, details: array<string, mixed>} */
    private function testMysql(string $dumpPath): array
    {
        $base = config('database.connections.mysql');
        $tempDatabase = ($base['database'] ?? 'pekangame').'_restore_test_'.time();
        $clientArgs = $this->mysqlClientArgs($base);

        $create = Process::fromShellCommandline(
            sprintf('mysql %s -e %s', $clientArgs, escapeshellarg("CREATE DATABASE IF NOT EXISTS `{$tempDatabase}`")),
        );
        $create->run();

        if (! $create->isSuccessful()) {
            return $this->failed('could not create throwaway restore-test database: '.$create->getErrorOutput());
        }

        try {
            $restore = Process::fromShellCommandline(
                sprintf('mysql %s %s < %s', $clientArgs, escapeshellarg($tempDatabase), escapeshellarg($dumpPath)),
            );
            $restore->setTimeout(300);
            $restore->run();

            if (! $restore->isSuccessful()) {
                return $this->failed('mysql restore command failed: '.$restore->getErrorOutput());
            }

            return $this->verify(array_merge($base, ['database' => $tempDatabase]));
        } finally {
            Process::fromShellCommandline(
                sprintf('mysql %s -e %s', $clientArgs, escapeshellarg("DROP DATABASE IF EXISTS `{$tempDatabase}`")),
            )->run();
        }
    }

    /** @param array<string, mixed> $config */
    private function mysqlClientArgs(array $config): string
    {
        $parts = [
            '--host='.escapeshellarg((string) ($config['host'] ?? '127.0.0.1')),
            '--port='.escapeshellarg((string) ($config['port'] ?? 3306)),
            '--user='.escapeshellarg((string) ($config['username'] ?? 'root')),
        ];

        if (! empty($config['password'])) {
            $parts[] = '--password='.escapeshellarg((string) $config['password']);
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $connectionConfig
     * @return array{passed: bool, details: array<string, mixed>}
     */
    private function verify(array $connectionConfig): array
    {
        config(['database.connections.'.self::CONNECTION => $connectionConfig]);
        DB::purge(self::CONNECTION);

        try {
            $tableCountQuery = $connectionConfig['driver'] === 'sqlite'
                ? "SELECT name FROM sqlite_master WHERE type = 'table'"
                : 'SHOW TABLES';

            $tableCount = count(DB::connection(self::CONNECTION)->select($tableCountQuery));

            Artisan::call('migrate:status', ['--database' => self::CONNECTION]);
            $statusOutput = Artisan::output();
            $hasPending = str_contains($statusOutput, 'Pending');
            $hasRan = str_contains($statusOutput, 'Ran');

            return [
                'passed' => $tableCount > 0 && $hasRan && ! $hasPending,
                'details' => [
                    'table_count' => $tableCount,
                    'migrate_status_has_pending' => $hasPending,
                    'migrate_status_has_ran' => $hasRan,
                ],
            ];
        } catch (Throwable $e) {
            return $this->failed('restore-test verification failed: '.$e->getMessage());
        } finally {
            DB::purge(self::CONNECTION);
            config(['database.connections.'.self::CONNECTION => null]);
        }
    }

    /** @return array{passed: bool, details: array<string, mixed>} */
    private function failed(string $reason): array
    {
        return ['passed' => false, 'details' => ['error' => $reason]];
    }
}
