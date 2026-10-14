<?php

namespace Tests\Unit\Services\Backup;

use App\Services\Backup\BackupRestorer;
use App\Services\Backup\BackupRestoreTester;
use Illuminate\Support\Facades\DB;
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

        return $this->dumps[] = $path;
    }

    /** @var list<string> */
    private array $dumps = [];

    protected function tearDown(): void
    {
        array_map(fn (string $path) => @unlink($path), $this->dumps);

        parent::tearDown();
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
        $this->assertSame([], $result['details']['pending_migrations']);
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
     * restore) must be caught as a pending migration, not silently pass
     * because the table has some rows.
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
        $this->assertCount(1, $result['details']['pending_migrations']);
    }

    private function validDump(): string
    {
        $sql = "CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER);\n";

        foreach ($this->realMigrationNames() as $name) {
            $sql .= "INSERT INTO migrations (migration, batch) VALUES ('{$name}', 1);\n";
        }

        return $this->writeDump($sql);
    }

    /**
     * Item 97: a dump cut short after `migrations` used to pass. Every live
     * table must come back, except the dump's own exclusions.
     */
    public function test_fails_when_a_live_table_is_missing_from_the_restore(): void
    {
        DB::statement('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER)');
        DB::statement('CREATE TABLE orders (id INTEGER PRIMARY KEY)');

        $result = app(BackupRestoreTester::class)->test($this->validDump());

        $this->assertFalse($result['passed']);
        $this->assertSame(['orders'], $result['details']['missing_tables']);
    }

    public function test_a_table_the_dump_excludes_is_not_counted_as_missing(): void
    {
        DB::statement('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER)');
        DB::statement('CREATE TABLE player_validations (id INTEGER PRIMARY KEY)');

        $result = app(BackupRestoreTester::class)->test($this->validDump());

        $this->assertTrue($result['passed'], json_encode($result['details']));
    }

    public function test_the_restorer_refuses_a_target_that_is_not_empty(): void
    {
        $target = storage_path('app/backup-temp/not-empty-'.uniqid('', true).'.sqlite');
        touch($target);
        (new \PDO('sqlite:'.$target))->exec('CREATE TABLE ledger_entries (id INTEGER PRIMARY KEY)');

        try {
            $this->expectExceptionMessage('is not empty');
            app(BackupRestorer::class)->restore($this->validDump(), $target);
        } finally {
            @unlink($target);
        }
    }

    public function test_fails_gracefully_for_a_corrupt_dump_file(): void
    {
        $result = app(BackupRestoreTester::class)->test($this->writeDump('this is not valid SQL at all ;;; ((('));

        $this->assertFalse($result['passed']);
        $this->assertArrayHasKey('error', $result['details']);
    }
}
