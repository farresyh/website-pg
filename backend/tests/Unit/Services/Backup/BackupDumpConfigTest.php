<?php

namespace Tests\Unit\Services\Backup;

use App\Services\Backup\BackupRestorer;
use Spatie\Backup\Tasks\Backup\DbDumperFactory;
use Tests\TestCase;

/**
 * ADR-144: the real mysqldump commands the nightly backup runs. The main
 * dump skips the excluded tables; the companion dump carries their
 * structure only, with the same safety flags (GTID 2026-09-14, ADR-099).
 * spatie ignores a misspelt dump option silently, so the flags are asserted
 * on the command itself.
 */
class BackupDumpConfigTest extends TestCase
{
    private function command(string $connection): string
    {
        return DbDumperFactory::createFromConnection($connection)->getDumpCommand('dump.sql', 'creds.cnf');
    }

    public function test_the_main_dump_skips_every_excluded_table(): void
    {
        $command = $this->command('mysql');
        $database = config('database.connections.mysql.database');

        foreach (config('database.connections.mysql.dump.exclude_tables') as $table) {
            $this->assertStringContainsString("--ignore-table={$database}.{$table}", $command);
        }

        $this->assertStringNotContainsString('--no-data', $command);
    }

    public function test_the_companion_dump_carries_only_the_structure_of_the_excluded_tables(): void
    {
        $command = $this->command(BackupRestorer::SCHEMA_CONNECTION);
        $excluded = config('database.connections.mysql.dump.exclude_tables');

        $this->assertStringContainsString('--no-data', $command);
        $this->assertStringContainsString('--tables '.implode(' ', $excluded), $command);
        $this->assertStringNotContainsString('--ignore-table', $command);
    }

    public function test_both_dumps_keep_the_safety_flags(): void
    {
        foreach (['mysql', BackupRestorer::SCHEMA_CONNECTION] as $connection) {
            $command = $this->command($connection);

            $this->assertStringContainsString('--set-gtid-purged=OFF', $command, $connection);
            $this->assertStringContainsString('--single-transaction', $command, $connection);
        }
    }

    public function test_the_excluded_list_is_one_list(): void
    {
        $this->assertSame(
            config('database.connections.mysql.dump.exclude_tables'),
            config('database.connections.sqlite.dump.exclude_tables'),
        );
    }

    /** Two dumps of one database would share a file name and overwrite each other. */
    public function test_dump_files_are_named_by_connection(): void
    {
        $this->assertSame('connection', config('backup.backup.database_dump_filename_base'));
    }
}
