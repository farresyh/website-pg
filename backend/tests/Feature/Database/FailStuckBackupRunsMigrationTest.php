<?php

namespace Tests\Feature\Database;

use App\Models\BackupRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item 97: runs stuck `running` since a worker-timeout kill are closed as
 * failed once; a run still in progress, a finished one, and a second run
 * of the migration are left alone.
 */
class FailStuckBackupRunsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(): void
    {
        (require database_path('migrations/2026_10_14_100000_fail_stuck_backup_runs.php'))->up();
    }

    private function backupRun(string $status, string $createdAt): BackupRun
    {
        $run = BackupRun::query()->create(['status' => $status, 'triggered_by' => 'system']);
        $run->forceFill(['created_at' => $createdAt])->save();

        return $run;
    }

    public function test_closes_only_old_stuck_runs_and_is_idempotent(): void
    {
        $stuck = $this->backupRun('running', now()->subDays(15)->toDateTimeString());
        $live = $this->backupRun('running', now()->subMinutes(2)->toDateTimeString());
        $done = $this->backupRun('success', now()->subDays(20)->toDateTimeString());

        $this->migrate();
        $firstMessage = $stuck->refresh()->error_message;
        $this->migrate();

        $this->assertSame('failed', $stuck->refresh()->status);
        $this->assertSame($firstMessage, $stuck->error_message);
        $this->assertSame('running', $live->refresh()->status);
        $this->assertSame('success', $done->refresh()->status);
        $this->assertNull($done->error_message);
    }
}
