<?php

namespace Tests\Feature\Services\Backup;

use App\Services\Backup\BackupArchivePassword;
use App\Services\Backup\BackupRestorer;
use App\Services\Backup\OpenWaBackup;
use Illuminate\Support\Facades\Storage;
use PDO;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * ADR-039 2026-10-22 addendum. A fake OpenWA install whose scripts keep
 * the real ones' contract (backup.sh: one openwa-backup-*.tar.gz in
 * BACKUP_DIR; restore.sh: the archive's files under OPENWA_DATA_DIR),
 * run as real processes, so encryption, the restore test and the clean
 * environment are proven end to end.
 */
class OpenWaBackupTest extends TestCase
{
    private string $root;

    private string $install;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/openwa-'.uniqid());
        $this->install = $this->root.'/OpenWA';
        mkdir($this->install.'/scripts', 0700, true);
        mkdir($this->install.'/data/baileys/sess-bot', 0700, true);
        mkdir($this->install.'/data/baileys/sess-cs', 0700, true);

        file_put_contents($this->install.'/scripts/backup.sh', <<<'SH'
            set -eu
            # The real script reads these before ./.env; Laravel's must never leak in.
            [ -z "${DATABASE_NAME:-}" ] || { echo "leaked DATABASE_NAME" >&2; exit 3; }
            tar czf "$BACKUP_DIR/openwa-backup-20261022-193700.tar.gz" -C data .
            SH);
        file_put_contents($this->install.'/scripts/restore.sh', <<<'SH'
            set -eu
            mkdir -p "$OPENWA_DATA_DIR"
            tar xzf "$1" -C "$OPENWA_DATA_DIR"
            SH);
        file_put_contents($this->install.'/.env', "PORT=2785\n");

        $this->sqlite('main.sqlite', ['CREATE TABLE api_keys (id)', 'INSERT INTO api_keys VALUES (1)']);
        $this->sqlite('openwa.sqlite', [
            'CREATE TABLE sessions (id)', "INSERT INTO sessions VALUES ('sess-bot'), ('sess-cs')",
            'CREATE TABLE messages (id)', 'INSERT INTO messages VALUES (1)',
        ]);
        foreach (['sess-bot', 'sess-cs'] as $session) {
            file_put_contents($this->install."/data/baileys/{$session}/creds.json", '{"me":"x"}');
            file_put_contents($this->install."/data/baileys/{$session}/pre-key-1.json", '{"k":1}');
        }

        config([
            'backup.openwa.install_dir' => $this->install,
            'backup.backup.password' => 'test-archive-password',
            'services.openwa.session_id' => 'sess-bot',
            'services.openwa.cs_session_id' => 'sess-cs',
        ]);
        Storage::fake('backups');
    }

    protected function tearDown(): void
    {
        putenv('DATABASE_NAME');
        unset($_ENV['DATABASE_NAME'], $_SERVER['DATABASE_NAME']);
        app(BackupRestorer::class)->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_a_backup_is_encrypted_uploaded_and_passes_its_restore_test(): void
    {
        $created = $this->backup()->create('backups', $this->root.'/work-create');

        $this->assertStringStartsWith('openwa/openwa-', $created['path']);
        Storage::disk('backups')->assertExists($created['path']);

        $zip = new ZipArchive;
        $zip->open(Storage::disk('backups')->path($created['path']));
        $this->assertSame(ZipArchive::EM_AES_256, $zip->statName('openwa.env')['encryption_method']);
        $this->assertFalse($zip->getFromName('openwa.env'), 'readable without the password');
        $zip->close();

        $result = $this->backup()->restoreTest('backups', $created['path'], $this->root.'/work-test');

        $this->assertTrue($result['passed'], json_encode($result['details']));
        $this->assertSame('ok', $result['details']['main_integrity']);
        $this->assertSame(2, $result['details']['sessions']);
        $this->assertSame(4, $result['details']['baileys_files']);
        $this->assertTrue($result['details']['env_included']);
    }

    public function test_the_wrong_password_fails_the_restore_test(): void
    {
        $created = $this->backup()->create('backups', $this->root.'/work-create');
        config(['backup.backup.password' => 'another-password']);

        $result = $this->backup()->restoreTest('backups', $created['path'], $this->root.'/work-test');

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('wrong BACKUP_ARCHIVE_PASSWORD', $result['details']['error']);
    }

    public function test_a_torn_baileys_file_fails_the_restore_test(): void
    {
        file_put_contents($this->install.'/data/baileys/sess-cs/pre-key-1.json', '{"k":');
        $created = $this->backup()->create('backups', $this->root.'/work-create');

        $result = $this->backup()->restoreTest('backups', $created['path'], $this->root.'/work-test');

        $this->assertFalse($result['passed']);
        $this->assertSame(['sess-cs/pre-key-1.json'], $result['details']['baileys_unreadable']);
    }

    public function test_a_configured_session_missing_from_the_archive_fails_the_restore_test(): void
    {
        config(['services.openwa.cs_session_id' => 'sess-gone']);
        $created = $this->backup()->create('backups', $this->root.'/work-create');

        $result = $this->backup()->restoreTest('backups', $created['path'], $this->root.'/work-test');

        $this->assertFalse($result['passed']);
        $this->assertSame(['sess-gone'], $result['details']['sessions_missing']);
        $this->assertSame(['sess-gone'], $result['details']['creds_missing']);
    }

    public function test_laravels_environment_never_reaches_the_openwa_scripts(): void
    {
        // Where Laravel's dotenv puts every .env value (putenv, $_ENV, $_SERVER),
        // and so where a child process would inherit it from.
        putenv('DATABASE_NAME=/home/forge/somewhere-else.sqlite');
        $_ENV['DATABASE_NAME'] = $_SERVER['DATABASE_NAME'] = '/home/forge/somewhere-else.sqlite';

        $created = $this->backup()->create('backups', $this->root.'/work-create');

        Storage::disk('backups')->assertExists($created['path']);
    }

    public function test_a_failing_backup_script_fails_loudly_with_its_output(): void
    {
        file_put_contents($this->install.'/scripts/backup.sh', "echo 'disk full' >&2; exit 1\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('disk full');

        $this->backup()->create('backups', $this->root.'/work-create');
    }

    public function test_an_unset_install_dir_fails_loudly(): void
    {
        config(['backup.openwa.install_dir' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OPENWA_INSTALL_DIR');

        $this->backup()->create('backups', $this->root.'/work-create');
    }

    public function test_production_refuses_to_back_up_without_a_password(): void
    {
        config(['backup.backup.password' => null]);
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BACKUP_ARCHIVE_PASSWORD');

        BackupArchivePassword::require();
    }

    public function test_prune_deletes_only_openwa_archives_past_the_window(): void
    {
        $disk = Storage::disk('backups');
        foreach (['openwa/old.zip', 'openwa/recent.zip', 'PekanGame/old-db.zip'] as $file) {
            $disk->put($file, 'x');
        }
        touch($disk->path('openwa/old.zip'), now()->subDays(15)->getTimestamp());
        touch($disk->path('PekanGame/old-db.zip'), now()->subDays(30)->getTimestamp());

        $deleted = $this->backup()->prune('backups');

        $this->assertSame(['openwa/old.zip'], $deleted);
        $disk->assertExists(['openwa/recent.zip', 'PekanGame/old-db.zip']);
    }

    private function backup(): OpenWaBackup
    {
        return app(OpenWaBackup::class);
    }

    /** @param list<string> $statements */
    private function sqlite(string $file, array $statements): void
    {
        $pdo = new PDO('sqlite:'.$this->install.'/data/'.$file);
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    }
}
