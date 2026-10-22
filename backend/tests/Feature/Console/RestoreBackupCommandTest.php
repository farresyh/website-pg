<?php

namespace Tests\Feature\Console;

use App\Models\BackupRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Item 97 (ADR-039 2026-10-14 addendum): the operator's restore path,
 * the same BackupRestorer the nightly restore test proves.
 */
class RestoreBackupCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        array_map(fn (string $path) => @unlink($path), $this->cleanup);

        parent::tearDown();
    }

    private function archive(): string
    {
        $sql = "CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER);\n";

        foreach (glob(database_path('migrations/*.php')) as $file) {
            $sql .= "INSERT INTO migrations (migration, batch) VALUES ('".basename($file, '.php')."', 1);\n";
        }

        $zipPath = storage_path('app/backup-temp/cmd-test-'.uniqid('', true).'.zip');
        @mkdir(dirname($zipPath), 0755, true);
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('db-dumps/sqlite-database.sql', $sql);
        $zip->close();

        return $this->cleanup[] = $zipPath;
    }

    private function emptyTarget(): string
    {
        $path = storage_path('app/backup-temp/cmd-target-'.uniqid('', true).'.sqlite');
        touch($path);

        return $this->cleanup[] = $path;
    }

    public function test_restores_a_local_archive_into_an_empty_database(): void
    {
        $target = $this->emptyTarget();

        $this->artisan('app:restore-backup', ['--file' => $this->archive(), '--database' => $target])
            ->expectsOutputToContain('Restore verified')
            ->assertSuccessful();

        $this->assertNotEmpty((new \PDO('sqlite:'.$target))->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
    }

    public function test_restores_a_run_from_the_backup_disk(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('PekanGame/a.zip', file_get_contents($this->archive()));
        $run = BackupRun::query()->create(['status' => 'success', 'triggered_by' => 'system', 'disk' => 'local', 'path' => 'PekanGame/a.zip', 'restore_test_passed' => true]);

        $this->artisan('app:restore-backup', ['--run' => $run->id, '--database' => $this->emptyTarget()])
            ->assertSuccessful();
    }

    public function test_refuses_a_target_that_is_not_empty(): void
    {
        $target = $this->emptyTarget();
        (new \PDO('sqlite:'.$target))->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY)');

        $this->artisan('app:restore-backup', ['--file' => $this->archive(), '--database' => $target])
            ->expectsOutputToContain('is not empty')
            ->assertFailed();
    }

    public function test_needs_exactly_one_source_and_a_target(): void
    {
        $this->artisan('app:restore-backup', ['--database' => 'x'])->assertFailed();
        $this->artisan('app:restore-backup', ['--file' => 'a.zip', '--path' => 'b.zip', '--database' => 'x'])->assertFailed();
        $this->artisan('app:restore-backup', ['--file' => 'a.zip'])->assertFailed();
    }

    public function test_refuses_an_openwa_run(): void
    {
        $run = BackupRun::query()->create(['source' => 'openwa', 'status' => 'success', 'triggered_by' => 'system', 'disk' => 'local', 'path' => 'openwa/x.zip']);

        $this->artisan('app:restore-backup', ['--run' => $run->id, '--database' => $this->emptyTarget()])
            ->expectsOutputToContain('app:restore-openwa-backup')
            ->assertFailed();
    }
}
