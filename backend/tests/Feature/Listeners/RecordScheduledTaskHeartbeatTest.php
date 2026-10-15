<?php

namespace Tests\Feature\Listeners;

use App\Models\ScheduledTaskHeartbeat;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-122 decision 2: what counts as a heartbeat, and that recording one
 * can never break `schedule:run`.
 */
class RecordScheduledTaskHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private function task(string $name = 'payment-reconciliation'): Event
    {
        return (new Schedule)->command('app:reconcile-pending-payments')->name($name);
    }

    public function test_a_zero_exit_code_records_ok_with_runtime(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:05');
        $event = $this->task();
        $event->exitCode = 0;

        event(new ScheduledTaskFinished($event, 4.2));

        $row = ScheduledTaskHeartbeat::query()->where('name', 'payment-reconciliation')->sole();
        $this->assertSame('2026-10-15 10:00:05', $row->last_ok_at->toDateTimeString());
        $this->assertSame(4.2, (float) $row->last_runtime_seconds);
    }

    public function test_a_non_zero_exit_code_is_not_ok(): void
    {
        $event = $this->task();
        $event->exitCode = 1;

        event(new ScheduledTaskFinished($event, 1.0));

        $this->assertNull(ScheduledTaskHeartbeat::query()->where('name', 'payment-reconciliation')->value('last_ok_at'));
    }

    /**
     * Laravel dispatches ScheduledTaskFinished with a null exit code when a
     * task is skipped for overlap — a stuck lock must not read as healthy.
     */
    public function test_a_task_skipped_for_overlap_is_not_ok(): void
    {
        $event = $this->task();
        $event->skippedBecauseOverlapping = true;

        event(new ScheduledTaskFinished($event, 0.0));

        $this->assertNull(ScheduledTaskHeartbeat::query()->where('name', 'payment-reconciliation')->value('last_ok_at'));
    }

    public function test_a_failure_records_time_and_error_and_keeps_the_last_ok(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        $event = $this->task();
        $event->exitCode = 0;
        event(new ScheduledTaskFinished($event, 1.0));

        Carbon::setTestNow('2026-10-15 10:15:00');
        event(new ScheduledTaskFailed($event, new RuntimeException(str_repeat('x', 3000))));

        $row = ScheduledTaskHeartbeat::query()->where('name', 'payment-reconciliation')->sole();
        $this->assertSame('2026-10-15 10:00:00', $row->last_ok_at->toDateTimeString());
        $this->assertSame('2026-10-15 10:15:00', $row->last_failed_at->toDateTimeString());
        $this->assertSame(2000, mb_strlen($row->last_error));
    }

    /**
     * ScheduledTaskFailed is dispatched from inside ScheduleRunCommand's
     * catch block; a throw here would abort every other task due that minute.
     */
    public function test_a_write_failure_never_escapes_the_listener(): void
    {
        Schema::drop('scheduled_task_heartbeats');
        $event = $this->task();
        $event->exitCode = 0;

        event(new ScheduledTaskFinished($event, 1.0));
        event(new ScheduledTaskFailed($event, new RuntimeException('boom')));

        $this->addToAssertionCount(1);
    }
}
