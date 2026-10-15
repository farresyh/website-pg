<?php

namespace App\Services\Scheduling;

use App\Console\TaskSchedule;
use App\Models\ScheduledTaskHeartbeat;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

/**
 * ADR-122: judges each scheduled task's heartbeat against its own cron
 * expression and timezone. Reads the schedule from TaskSchedule, since an
 * HTTP request never loads routes/console.php.
 *
 * A due time counts once its grace has passed (30 min, or 60 for a daily
 * task, whose 00:00 batch runs one task after another). A sub-daily task
 * may miss one due run; a daily task may not.
 */
class SchedulerLiveness
{
    private const GRACE_MINUTES = 30;

    private const DAILY_GRACE_MINUTES = 60;

    /**
     * @return list<array{name: string, tier: string, stale: bool, last_ok_at: ?string, last_failed_at: ?string, last_error: ?string, last_runtime_seconds: ?float}>
     */
    public function tasks(): array
    {
        $schedule = new Schedule;
        TaskSchedule::define($schedule);
        $events = collect($schedule->events());
        $heartbeats = $this->heartbeats($events->pluck('description')->all());
        $now = Carbon::now();

        return $events->map(function (Event $event) use ($heartbeats, $now) {
            $heartbeat = $heartbeats[$event->description];

            return [
                'name' => $event->description,
                'tier' => TaskSchedule::isCritical($event->description) ? 'critical' : 'routine',
                'stale' => $this->isStale($event, $heartbeat->last_ok_at ?? $heartbeat->created_at, $now),
                'last_ok_at' => $heartbeat->last_ok_at?->toISOString(),
                'last_failed_at' => $heartbeat->last_failed_at?->toISOString(),
                'last_error' => $heartbeat->last_error,
                'last_runtime_seconds' => $heartbeat->last_runtime_seconds === null ? null : (float) $heartbeat->last_runtime_seconds,
            ];
        })->values()->all();
    }

    public function criticalTasksHealthy(): bool
    {
        return ! collect($this->tasks())->contains(fn (array $task) => $task['tier'] === 'critical' && $task['stale']);
    }

    /**
     * A task with no row yet is seeded now (decision 8), so one that never
     * runs goes stale one interval plus grace later.
     *
     * @param  list<string>  $names
     * @return array<string, ScheduledTaskHeartbeat>
     */
    private function heartbeats(array $names): array
    {
        $existing = ScheduledTaskHeartbeat::query()->whereIn('name', $names)->get()->keyBy('name');
        $missing = array_values(array_diff($names, $existing->keys()->all()));

        if ($missing === []) {
            return $existing->all();
        }

        $now = now();
        ScheduledTaskHeartbeat::query()->insertOrIgnore(
            array_map(fn (string $name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now], $missing),
        );

        return ScheduledTaskHeartbeat::query()->whereIn('name', $names)->get()->keyBy('name')->all();
    }

    private function isStale(Event $event, CarbonInterface $baseline, CarbonInterface $now): bool
    {
        $cron = new CronExpression($event->getExpression());
        $timezone = $event->timezone ?? config('app.timezone');

        $intervalSeconds = $cron->getNextRunDate($now, 1, false, $timezone)->getTimestamp()
            - $cron->getNextRunDate($now, 0, false, $timezone)->getTimestamp();
        $daily = $intervalSeconds >= 86400;

        $cutoff = $now->copy()->subMinutes($daily ? self::DAILY_GRACE_MINUTES : self::GRACE_MINUTES);
        $required = $cron->getPreviousRunDate($cutoff, $daily ? 0 : 1, true, $timezone);

        return $baseline->getTimestamp() < $required->getTimestamp();
    }
}
