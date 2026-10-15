<?php

namespace Tests\Feature\Services\Scheduling;

use App\Console\TaskSchedule;
use App\Models\ScheduledTaskHeartbeat;
use App\Services\Scheduling\SchedulerLiveness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-122 decision 4 (staleness rule) and 8 (lazy seed), against the real
 * TaskSchedule definitions. Times are UTC, the scheduler's own timezone.
 */
class SchedulerLivenessTest extends TestCase
{
    use RefreshDatabase;

    private function heartbeat(string $name, string $createdAt, ?string $lastOkAt): void
    {
        DB::table('scheduled_task_heartbeats')->updateOrInsert(
            ['name' => $name],
            ['last_ok_at' => $lastOkAt, 'created_at' => $createdAt, 'updated_at' => $createdAt],
        );
    }

    private function stale(string $name): bool
    {
        return collect(app(SchedulerLiveness::class)->tasks())->firstWhere('name', $name)['stale'];
    }

    public function test_the_first_look_seeds_every_task_and_none_is_stale(): void
    {
        $tasks = app(SchedulerLiveness::class)->tasks();

        $this->assertCount(count(TaskSchedule::CRITICAL) + count(TaskSchedule::ROUTINE), $tasks);
        $this->assertSame(count($tasks), ScheduledTaskHeartbeat::query()->count());
        $this->assertFalse(collect($tasks)->contains('stale', true));
        $this->assertTrue(app(SchedulerLiveness::class)->criticalTasksHealthy());
    }

    public function test_a_sub_daily_task_may_miss_one_run_and_is_stale_after_two(): void
    {
        $this->heartbeat('payment-reconciliation', '2026-10-15 09:00:00', '2026-10-15 10:00:20');

        Carbon::setTestNow('2026-10-15 10:46:00'); // missed 10:15, 10:30 still in grace
        $this->assertFalse($this->stale('payment-reconciliation'));

        Carbon::setTestNow('2026-10-15 11:01:00'); // missed 10:15 and 10:30
        $this->assertTrue($this->stale('payment-reconciliation'));
    }

    public function test_a_daily_task_is_stale_after_one_missed_run_with_a_60_minute_grace(): void
    {
        $this->heartbeat('database-backup', '2026-10-01 00:00:00', '2026-10-14 02:07:30');

        Carbon::setTestNow('2026-10-15 03:06:00');
        $this->assertFalse($this->stale('database-backup'));

        Carbon::setTestNow('2026-10-15 03:08:00');
        $this->assertTrue($this->stale('database-backup'));
    }

    public function test_due_times_follow_the_tasks_own_timezone(): void
    {
        // 10:00 Malaysia time = 02:00 UTC.
        $this->heartbeat('membership-renewal-reminders', '2026-10-01 00:00:00', '2026-10-14 02:00:10');

        Carbon::setTestNow('2026-10-15 02:59:00');
        $this->assertFalse($this->stale('membership-renewal-reminders'));

        Carbon::setTestNow('2026-10-15 03:01:00');
        $this->assertTrue($this->stale('membership-renewal-reminders'));
    }

    public function test_before_the_first_success_the_row_creation_is_the_baseline(): void
    {
        $this->heartbeat('payment-reconciliation', '2026-10-15 10:20:00', null);

        Carbon::setTestNow('2026-10-15 11:01:00');
        $this->assertFalse($this->stale('payment-reconciliation'));

        Carbon::setTestNow('2026-10-15 11:16:00');
        $this->assertTrue($this->stale('payment-reconciliation'));
    }

    public function test_a_row_for_a_task_no_longer_scheduled_is_ignored(): void
    {
        $this->heartbeat('removed-task', '2026-01-01 00:00:00', null);

        $this->assertNull(collect(app(SchedulerLiveness::class)->tasks())->firstWhere('name', 'removed-task'));
        $this->assertTrue(app(SchedulerLiveness::class)->criticalTasksHealthy());
    }

    public function test_only_a_stale_critical_task_fails_the_check(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->heartbeat('horizon-snapshot', '2026-10-15 08:00:00', null);
        $this->assertTrue($this->stale('horizon-snapshot'));
        $this->assertTrue(app(SchedulerLiveness::class)->criticalTasksHealthy());

        $this->heartbeat('payment-reconciliation', '2026-10-15 08:00:00', null);
        $this->assertFalse(app(SchedulerLiveness::class)->criticalTasksHealthy());
    }
}
