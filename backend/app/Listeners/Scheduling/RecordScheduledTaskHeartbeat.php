<?php

namespace App\Listeners\Scheduling;

use App\Models\ScheduledTaskHeartbeat;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * ADR-122 decision 2. Two traps in Laravel's scheduler shape this:
 *
 * - A task skipped for overlap still fires ScheduledTaskFinished, with a
 *   null exit code. Only `exitCode === 0` on a task that actually ran is
 *   OK, or a stuck lock would read as healthy.
 * - ScheduledTaskFailed fires from inside ScheduleRunCommand's catch
 *   block, so a throw here would abort every other task due that minute.
 *   A failed write is logged and dropped.
 */
class RecordScheduledTaskHeartbeat
{
    public function handleFinished(ScheduledTaskFinished $event): void
    {
        $task = $event->task;

        if ($task->skippedBecauseOverlapping || $task->exitCode !== 0) {
            return;
        }

        $this->record($task->description, [
            'last_ok_at' => now(),
            'last_runtime_seconds' => $event->runtime,
        ]);
    }

    public function handleFailed(ScheduledTaskFailed $event): void
    {
        $this->record($event->task->description, [
            'last_failed_at' => now(),
            'last_error' => Str::limit($event->exception->getMessage(), 2000, ''),
        ]);
    }

    private function record(?string $name, array $values): void
    {
        if ($name === null) {
            return;
        }

        try {
            ScheduledTaskHeartbeat::query()->updateOrCreate(['name' => $name], $values);
        } catch (Throwable $e) {
            Log::error("[Scheduler] could not record heartbeat for {$name}: {$e->getMessage()}");
        }
    }
}
