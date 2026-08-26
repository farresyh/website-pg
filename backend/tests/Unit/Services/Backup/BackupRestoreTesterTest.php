<?php

namespace Tests\Unit\Services\Backup;

use App\Services\Backup\BackupRestoreTester;
use Tests\TestCase;

/**
 * ADR-039 decision 8: proves the restore-test itself actually detects
 * a good vs. a bad dump — the point of this feature is that a backup
 * is never trusted just because `backup:run` exited 0. Exercises the
 * real `sqlite3` binary (same dependency spatie's own Sqlite dumper
 * already requires), not a mock — matches this project's established
 * "real-subprocess proofs" habit (see the concurrency test suite).
 */
class BackupRestoreTesterTest extends TestCase
{
    private function realMigrationNames(): array
    {
        return array_map(
            fn (string $path) => basename($path, '.php'),
            glob(database_path('migrations/*.php')),
        );
    }

    private function writeDump(string $sql): string
    {
        $path = storage_path('app/backup-temp/test-dump-'.uniqid('', true).'.sql');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $sql);

        return $path;
    }

    public function test_passes_for_a_dump_whose_migrations_table_matches_every_real_migration_file(): void
    {
        $sql = "CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER);\n";

        foreach ($this->realMigrationNames() as $name) {
            $sql .= "INSERT INTO migrations (migration, batch) VALUES ('{$name}', 1);\n";
        }

        $sql .= "CREATE TABLE sample_table (id INTEGER PRIMARY KEY, value TEXT);\n";
        $sql .= "INSERT INTO sample_table (value) VALUES ('x');\n";

        $result = app(BackupRestoreTester::class)->test($this->writeDump($sql));

        $this->assertTrue($result['passed'], json_encode($result['details']));
        $this->assertGreaterThanOrEqual(2, $result['details']['table_count']);
        $this->assertTrue($result['details']['migrate_status_has_ran']);
        $this->assertFalse($result['details']['migrate_status_has_pending']);
    }

    /**
     * A dump missing the `migrations` table entirely (e.g. a corrupted
     * or partial dump) must never be reported as a passing restore.
     */
    public function test_fails_when_the_migrations_table_is_missing(): void
    {
        $sql = "CREATE TABLE sample_table (id INTEGER PRIMARY KEY, value TEXT);\n".
            "INSERT INTO sample_table (value) VALUES ('x');\n";

        $result = app(BackupRestoreTester::class)->test($this->writeDump($sql));

        $this->assertFalse($result['passed']);
    }

    /**
     * A dump missing one real migration (simulating a partial/corrupt
     * restore) must be caught by `migrate:status` reporting a pending
     * migration, not silently pass because the table has some rows.
     */
    public function test_fails_when_a_real_migration_is_missing_from_the_dump(): void
    {
        $names = $this->realMigrationNames();
        array_pop($names);

        $sql = "CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER);\n";

        foreach ($names as $name) {
            $sql .= "INSERT INTO migrations (migration, batch) VALUES ('{$name}', 1);\n";
        }

        $result = app(BackupRestoreTester::class)->test($this->writeDump($sql));

        $this->assertFalse($result['passed']);
        $this->assertTrue($result['details']['migrate_status_has_pending']);
    }

    public function test_fails_gracefully_for_a_corrupt_dump_file(): void
    {
        $result = app(BackupRestoreTester::class)->test($this->writeDump('this is not valid SQL at all ;;; ((('));

        $this->assertFalse($result['passed']);
        $this->assertArrayHasKey('error', $result['details']);
    }
}
