<?php

namespace Tests\Concurrency;

use App\Services\Backup\BackupRestoreTester;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PDO;
use Spatie\Backup\Tasks\Backup\DbDumperFactory;
use Tests\TestCase;

/**
 * Item 97 (ADR-039 2026-10-14 addendum). Not a concurrency test: it lives
 * in this suite because it needs a real MySQL server, and the restore test
 * failed in production twice on behaviour sqlite cannot show (GTID on
 * 2026-09-14, a missing CREATE grant from 2026-10-01).
 *
 * The app user `kerox` mirrors prod's `pekangame_app`: privileges on its own
 * schema only, plus the one extra grant ADR-117's addendum adds for
 * throwaway restore-test databases. A view owned by another user (root,
 * standing in for prod's `doadmin`) must still restore.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class BackupRestoreMysqlTest extends TestCase
{
    use DatabaseMigrations;

    private function root(): PDO
    {
        return new PDO('mysql:host='.config('database.connections.mysql.host').';port='.config('database.connections.mysql.port'), 'root', 'root');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Prod's DigitalOcean cluster runs partial_revokes = ON, which makes
        // `_` and `%` in a database-level GRANT literal: the 2026-10-14
        // wildcard grant matched nothing there (item 97, run #45). Mirror it.
        $root = $this->root();
        $root->exec('SET GLOBAL partial_revokes = ON');
        $root->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER, CREATE VIEW, SHOW VIEW, LOCK TABLES ON `kerox_restore_test`.* TO 'kerox'@'%'");
        $root->exec('CREATE OR REPLACE DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW kerox.foreign_definer_probe AS SELECT 1 AS one');
    }

    /** @var list<string> */
    private array $dumps = [];

    protected function tearDown(): void
    {
        $root = $this->root();
        $root->exec('DROP VIEW IF EXISTS kerox.foreign_definer_probe');
        $root->exec('REVOKE ALL ON `kerox_restore_test`.* FROM \'kerox\'@\'%\'');
        $root->exec('SET GLOBAL partial_revokes = OFF');
        array_map(fn (string $path) => @unlink($path), $this->dumps);

        parent::tearDown();
    }

    private function dump(): string
    {
        $path = storage_path('app/backup-temp/mysql-test-dump-'.uniqid('', true).'.sql');
        @mkdir(dirname($path), 0755, true);
        DbDumperFactory::createFromConnection('mysql')->dumpToFile($path);
        $this->dumps[] = $path;

        return $path;
    }

    public function test_a_real_dump_with_a_foreign_definer_view_restores_as_the_app_user(): void
    {
        $result = app(BackupRestoreTester::class)->test($this->dump());

        $this->assertTrue($result['passed'], json_encode($result['details']));
        $this->assertSame([], $result['details']['missing_tables']);
    }

    public function test_the_throwaway_database_is_dropped_afterwards(): void
    {
        app(BackupRestoreTester::class)->test($this->dump());

        $leftover = DB::select("SHOW DATABASES LIKE 'kerox\\_restore\\_test'");
        $this->assertSame([], $leftover);
    }

    /** A run killed mid-restore leaves the throwaway database behind. */
    public function test_a_leftover_throwaway_database_does_not_block_the_next_run(): void
    {
        DB::statement('CREATE DATABASE kerox_restore_test');
        DB::statement('CREATE TABLE kerox_restore_test.half_restored (id INT)');

        $result = app(BackupRestoreTester::class)->test($this->dump());

        $this->assertTrue($result['passed'], json_encode($result['details']));
    }

    public function test_a_dump_missing_a_live_table_fails(): void
    {
        $path = $this->dump();
        DB::statement('CREATE TABLE added_after_dump (id INT PRIMARY KEY)');

        try {
            $result = app(BackupRestoreTester::class)->test($path);
        } finally {
            DB::statement('DROP TABLE added_after_dump');
        }

        $this->assertFalse($result['passed']);
        $this->assertSame(['added_after_dump'], $result['details']['missing_tables']);
    }
}
