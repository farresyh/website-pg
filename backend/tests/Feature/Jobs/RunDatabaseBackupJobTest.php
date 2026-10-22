<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RunDatabaseBackupJob;
use App\Models\BackupRun;
use App\Services\Backup\BackupFailureAlerter;
use App\Services\Backup\BackupRestorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Item 97 (ADR-039 2026-10-14 addendum): runs killed by the worker timeout
 * on 2026-09-26..30 stayed `running` forever, alerted nobody, and left
 * plaintext dumps in backup-temp.
 */
class RunDatabaseBackupJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_killed_run_is_marked_failed_and_alerts(): void
    {
        $run = BackupRun::query()->create(['status' => 'running', 'triggered_by' => 'system', 'started_at' => now()]);
        $this->mock(BackupFailureAlerter::class)->shouldReceive('alert')->once();

        (new RunDatabaseBackupJob($run))->failed(new TimeoutExceededException('RunDatabaseBackupJob has timed out.'));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('timed out', $run->error_message);
        $this->assertNotNull($run->finished_at);
    }

    public function test_a_run_sweeps_extract_folders_left_by_a_killed_run(): void
    {
        $leftover = storage_path('app/backup-temp/restore-extract-killed');
        @mkdir($leftover.'/db-dumps', 0700, true);
        file_put_contents($leftover.'/db-dumps/mysql-pekangame.sql', 'plaintext');
        $openWaLeftover = storage_path('app/backup-temp/openwa-backup-killed');
        @mkdir($openWaLeftover.'/test/restored/data/baileys', 0700, true);
        $other = storage_path('app/backup-temp/restore-cli-in-progress');
        @mkdir($other, 0700, true);

        app(BackupRestorer::class)->sweepJobLeftovers();

        $this->assertDirectoryDoesNotExist($leftover);
        $this->assertDirectoryDoesNotExist($openWaLeftover);
        $this->assertDirectoryExists($other);
        rmdir($other);
    }

    /** ADR-039 2026-10-22 addendum: prod wrote plain zips for weeks because the password was unset. */
    public function test_production_without_a_password_fails_the_run_instead_of_writing_a_plain_zip(): void
    {
        config(['backup.backup.password' => null]);
        $this->app['env'] = 'production';
        $run = BackupRun::query()->create(['status' => 'queued', 'triggered_by' => 'system']);
        Artisan::spy();
        $this->mock(BackupFailureAlerter::class)->shouldReceive('alert')->once();

        app()->call([new RunDatabaseBackupJob($run), 'handle']);

        Artisan::shouldNotHaveReceived('call');
        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('BACKUP_ARCHIVE_PASSWORD', $run->error_message);
    }
}
