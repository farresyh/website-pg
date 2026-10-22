<?php

namespace Tests\Feature\Console;

use App\Models\BackupRun;
use App\Services\Backup\BackupRestorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** ADR-039 2026-10-22 addendum: the operator's OpenWA disaster path. */
class RestoreOpenWaBackupCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $to;

    protected function setUp(): void
    {
        parent::setUp();

        config(['backup.backup.password' => 'test-archive-password', 'filesystems.backup_disk' => 'backups']);
        Storage::fake('backups');
        $this->to = storage_path('framework/testing/openwa-restore-'.uniqid());
    }

    protected function tearDown(): void
    {
        app(BackupRestorer::class)->deleteDirectory($this->to);

        parent::tearDown();
    }

    public function test_decrypts_a_run_into_the_target_folder(): void
    {
        $run = BackupRun::query()->create([
            'source' => 'openwa', 'status' => 'success', 'triggered_by' => 'system',
            'disk' => 'backups', 'path' => $this->putArchive('test-archive-password'), 'restore_test_passed' => true,
        ]);

        $this->artisan('app:restore-openwa-backup', ['--run' => $run->id, '--to' => $this->to])->assertSuccessful();

        $this->assertSame('tar-bytes', file_get_contents($this->to.'/openwa-backup-20261022-193700.tar.gz'));
        $this->assertSame("PORT=2785\n", file_get_contents($this->to.'/openwa.env'));
    }

    public function test_the_wrong_password_fails_and_writes_nothing(): void
    {
        $path = $this->putArchive('another-password');

        $this->artisan('app:restore-openwa-backup', ['--path' => $path, '--to' => $this->to])
            ->expectsOutputToContain('wrong BACKUP_ARCHIVE_PASSWORD')
            ->assertFailed();

        $this->assertDirectoryDoesNotExist($this->to);
    }

    public function test_refuses_a_database_run_and_a_non_empty_target(): void
    {
        $run = BackupRun::query()->create(['status' => 'success', 'triggered_by' => 'system', 'disk' => 'backups', 'path' => 'PekanGame/x.zip']);
        $this->artisan('app:restore-openwa-backup', ['--run' => $run->id, '--to' => $this->to])->assertFailed();

        mkdir($this->to, 0700, true);
        touch($this->to.'/something');
        $this->artisan('app:restore-openwa-backup', ['--path' => 'openwa/x.zip', '--to' => $this->to])->assertFailed();
    }

    private function putArchive(string $password): string
    {
        $local = storage_path('framework/testing/openwa-'.uniqid().'.zip');
        $zip = new ZipArchive;
        $zip->open($local, ZipArchive::CREATE);
        $zip->addFromString('openwa-backup-20261022-193700.tar.gz', 'tar-bytes');
        $zip->addFromString('openwa.env', "PORT=2785\n");
        foreach (['openwa-backup-20261022-193700.tar.gz', 'openwa.env'] as $name) {
            $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password);
        }
        $zip->close();

        Storage::disk('backups')->put('openwa/openwa-2026-10-22-19-37-00.zip', file_get_contents($local));
        unlink($local);

        return 'openwa/openwa-2026-10-22-19-37-00.zip';
    }
}
