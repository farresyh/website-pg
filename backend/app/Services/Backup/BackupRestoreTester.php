<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ADR-039 decision 8: every backup run is restore-tested automatically —
 * restore into a throwaway empty database through the same BackupRestorer
 * the `app:restore-backup` command uses (2026-10-14 addendum), then verify:
 * tables exist, `migrate:status` is clean, and every live table (minus the
 * dump's own exclusions) came back.
 *
 * On MySQL the throwaway database is `<db>_restore_test_<time>` on the
 * same server; the app user needs ADR-117's addendum grant on that name
 * pattern. Driver-aware: sqlite locally and in the fast test suite.
 */
class BackupRestoreTester
{
    public function __construct(private readonly BackupRestorer $restorer) {}

    /** @return array{passed: bool, details: array<string, mixed>} */
    public function test(string $dumpPath): array
    {
        $default = config('database.default');
        $base = config("database.connections.{$default}");
        $expected = array_values(array_diff($this->restorer->tables(), $base['dump']['exclude_tables'] ?? []));
        $isSqlite = $base['driver'] === 'sqlite';

        $target = $isSqlite
            ? storage_path('app/backup-temp/restore-test-'.uniqid('', true).'.sqlite')
            : ($base['database'] ?? 'pekangame').'_restore_test_'.time();

        try {
            if ($isSqlite) {
                touch($target);
            } else {
                DB::connection()->statement("CREATE DATABASE `{$target}`");
            }
        } catch (\Throwable $e) {
            return $this->failed('could not create throwaway restore-test database: '.$e->getMessage());
        }

        try {
            $this->restorer->restore($dumpPath, $target);

            return $this->restorer->verify($target, $expected);
        } catch (RuntimeException $e) {
            return $this->failed($e->getMessage());
        } finally {
            $isSqlite ? @unlink($target) : DB::connection()->statement("DROP DATABASE IF EXISTS `{$target}`");
        }
    }

    /** @return array{passed: bool, details: array<string, mixed>} */
    private function failed(string $reason): array
    {
        return ['passed' => false, 'details' => ['error' => $reason]];
    }
}
