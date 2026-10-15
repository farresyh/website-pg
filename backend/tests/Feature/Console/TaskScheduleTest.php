<?php

namespace Tests\Feature\Console;

use App\Console\TaskSchedule;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * ADR-122 decision 7: a scheduled task without a name, a tier, or the
 * short overlap lock can't reach production unnoticed.
 */
class TaskScheduleTest extends TestCase
{
    public function test_every_task_has_a_unique_name_one_tier_and_the_short_overlap_lock(): void
    {
        $schedule = new Schedule;
        TaskSchedule::define($schedule);
        $names = [];

        foreach ($schedule->events() as $event) {
            $name = $event->description;

            $this->assertNotEmpty($name, "Scheduled task [{$event->command}] has no name().");
            $this->assertNotContains($name, $names, "Scheduled task name [{$name}] is used twice.");
            $this->assertTrue(
                in_array($name, TaskSchedule::CRITICAL, true) xor in_array($name, TaskSchedule::ROUTINE, true),
                "Scheduled task [{$name}] must be in exactly one of TaskSchedule::CRITICAL / ROUTINE.",
            );
            $this->assertTrue($event->withoutOverlapping, "Scheduled task [{$name}] has no withoutOverlapping().");
            $this->assertSame(TaskSchedule::OVERLAP_EXPIRY_MINUTES, $event->expiresAt, "Scheduled task [{$name}] overlap lock is not ".TaskSchedule::OVERLAP_EXPIRY_MINUTES.' minutes.');

            $names[] = $name;
        }

        $this->assertEqualsCanonicalizing(array_merge(TaskSchedule::CRITICAL, TaskSchedule::ROUTINE), $names, 'A tier lists a task that is not scheduled.');
    }

    public function test_the_real_scheduler_runs_the_same_definitions(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $registered = collect(app(Schedule::class)->events())->pluck('description')->all();

        $this->assertEqualsCanonicalizing(array_merge(TaskSchedule::CRITICAL, TaskSchedule::ROUTINE), $registered);
    }
}
