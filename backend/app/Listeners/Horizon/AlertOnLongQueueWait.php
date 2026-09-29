<?php

namespace App\Listeners\Horizon;

use App\Services\Backup\BackupFailureAlerter;
use Illuminate\Support\Facades\Cache;
use Laravel\Horizon\Events\LongWaitDetected;

/**
 * ADR-048 addendum (2026-09-29, audit Wave 4 Low): Horizon's built-in
 * LongWait notification goes through the `Mail::` facade, which is `log`
 * in production — it would never reach anyone (the same trap
 * BackupFailureAlerter's own docblock records). Routed through that
 * alerter's Plunk path instead. Horizon re-fires this every monitor tick
 * while a queue stays slow, so one alert per connection:queue per 15 min.
 */
class AlertOnLongQueueWait
{
    public function __construct(private readonly BackupFailureAlerter $alerter) {}

    public function handle(LongWaitDetected $event): void
    {
        $queue = "{$event->connection}:{$event->queue}";

        if (! Cache::add("horizon-long-wait-alert:{$queue}", true, 900)) {
            return;
        }

        $this->alerter->alert(
            "{$queue} long wait",
            "Jobs on {$queue} have waited {$event->seconds}s (threshold: config/horizon.php waits). Check Horizon.",
            'Queue',
        );
    }
}
