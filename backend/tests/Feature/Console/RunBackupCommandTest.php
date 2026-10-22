<?php

namespace Tests\Feature\Console;

use App\Jobs\RunDatabaseBackupJob;
use App\Jobs\RunOpenWaBackupJob;
use App\Models\BackupRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** ADR-039: the schedule's entry point; `--source` since the 2026-10-22 addendum. */
class RunBackupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_source_queues_its_own_job(): void
    {
        Queue::fake();

        $this->artisan('app:run-backup', ['--source' => 'database'])->assertSuccessful();
        $this->artisan('app:run-backup', ['--source' => 'openwa'])->assertSuccessful();

        Queue::assertPushed(RunDatabaseBackupJob::class, 1);
        Queue::assertPushed(RunOpenWaBackupJob::class, 1);
        $this->assertSame(['database', 'openwa'], BackupRun::query()->orderBy('id')->pluck('source')->all());
    }

    public function test_an_unknown_source_queues_nothing(): void
    {
        Queue::fake();

        $this->artisan('app:run-backup', ['--source' => 'everything'])->assertFailed();

        Queue::assertNothingPushed();
    }
}
