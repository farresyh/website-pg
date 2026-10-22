<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RunOpenWaBackupJob;
use App\Models\BackupRun;
use App\Services\Backup\BackupFailureAlerter;
use App\Services\Backup\OpenWaBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/** ADR-039 2026-10-22 addendum: the OpenWA source's run lifecycle. */
class RunOpenWaBackupJobTest extends TestCase
{
    use RefreshDatabase;

    private BackupRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.backup_disk' => 'backups']);
        $this->run = BackupRun::query()->create(['source' => BackupRun::SOURCE_OPENWA, 'status' => 'queued', 'triggered_by' => 'system']);
    }

    public function test_a_passing_run_is_recorded_and_then_prunes(): void
    {
        $this->mockBackup(fn (MockInterface $mock) => $mock
            ->shouldReceive('restoreTest')->once()->andReturn(['passed' => true, 'details' => ['sessions' => 2]])
            ->getMock()->shouldReceive('prune')->once()->with('backups')->andReturn([]));
        $this->mock(BackupFailureAlerter::class)->shouldNotReceive('alert');

        $this->handle();

        $this->run->refresh();
        $this->assertSame('success', $this->run->status);
        $this->assertSame('openwa/openwa-x.zip', $this->run->path);
        $this->assertSame(123, $this->run->size_bytes);
        $this->assertTrue($this->run->restore_test_passed);
        $this->assertSame('backups', $this->run->disk);
    }

    /** Pruning only after a pass: a run of failures never thins out the last good copies. */
    public function test_a_failed_restore_test_alerts_and_never_prunes(): void
    {
        $this->mockBackup(fn (MockInterface $mock) => $mock
            ->shouldReceive('restoreTest')->once()->andReturn(['passed' => false, 'details' => ['baileys_unreadable' => ['a.json']]])
            ->getMock()->shouldNotReceive('prune'));
        $this->mock(BackupFailureAlerter::class)->shouldReceive('alert')->once();

        $this->handle();

        $this->run->refresh();
        $this->assertSame('failed', $this->run->status);
        $this->assertFalse($this->run->restore_test_passed);
        $this->assertSame('openwa/openwa-x.zip', $this->run->path);
    }

    public function test_a_failing_create_is_recorded_alerted_and_leaves_no_work_dir(): void
    {
        $this->mock(OpenWaBackup::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('create')->once()->andThrow(new RuntimeException('OpenWA backup.sh failed (exit 1): disk full'))
            ->getMock()->shouldNotReceive('prune'));
        $this->mock(BackupFailureAlerter::class)->shouldReceive('alert')->once();

        $this->handle();

        $this->run->refresh();
        $this->assertSame('failed', $this->run->status);
        $this->assertStringContainsString('disk full', $this->run->error_message);
        $this->assertSame([], glob(storage_path('app/backup-temp/openwa-backup-*'), GLOB_ONLYDIR));
    }

    public function test_a_killed_run_is_marked_failed_and_alerts(): void
    {
        $this->mock(BackupFailureAlerter::class)->shouldReceive('alert')->once();

        (new RunOpenWaBackupJob($this->run))->failed(new RuntimeException('timed out'));

        $this->run->refresh();
        $this->assertSame('failed', $this->run->status);
        $this->assertStringContainsString('timed out', $this->run->error_message);
    }

    public function test_it_runs_on_the_backups_lane(): void
    {
        $this->assertSame('backups', (new RunOpenWaBackupJob($this->run))->queue);
    }

    private function mockBackup(callable $more): void
    {
        $this->mock(OpenWaBackup::class, function (MockInterface $mock) use ($more) {
            $mock->shouldReceive('create')->once()->andReturn(['path' => 'openwa/openwa-x.zip', 'size_bytes' => 123]);
            $more($mock);
        });
    }

    private function handle(): void
    {
        app()->call([new RunOpenWaBackupJob($this->run), 'handle']);
    }
}
