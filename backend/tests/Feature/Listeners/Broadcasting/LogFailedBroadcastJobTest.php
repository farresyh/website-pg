<?php

namespace Tests\Feature\Listeners\Broadcasting;

use App\Jobs\SyncSupplierPricesJob;
use App\Listeners\Broadcasting\LogFailedBroadcastJob;
use Exception;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * ADR-047 2026-09-28 addendum — the one runnable check on the filter
 * this listener's whole job is: log a failed BroadcastEvent job, never
 * every failed job app-wide.
 */
class LogFailedBroadcastJobTest extends TestCase
{
    private function fire(string $jobClass, string $queue): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn($jobClass);
        $job->shouldReceive('getQueue')->andReturn($queue);

        app(LogFailedBroadcastJob::class)->handle(
            new JobFailed('redis', $job, new Exception('Reverb unreachable')),
        );
    }

    public function test_logs_a_broadcast_event_job_failing_on_a_broadcast_queue(): void
    {
        Log::shouldReceive('error')->once()->with(
            'Broadcast job exhausted retries — a live UI update was dropped',
            Mockery::on(fn (array $context) => $context['queue'] === 'price-sync'),
        );

        $this->fire(BroadcastEvent::class, 'price-sync');
    }

    public function test_ignores_a_non_broadcast_job_failing_on_the_same_queue(): void
    {
        Log::shouldReceive('error')->never();

        $this->fire(SyncSupplierPricesJob::class, 'price-sync');
    }

    /**
     * 2026-11-10: the listener only watched orders/price-sync/backups, but
     * OrderStatusUpdated (the customer's live order page) broadcasts on
     * `default`, so its failures were never logged. Any queue counts now.
     */
    public function test_logs_a_broadcast_event_job_failing_on_any_queue(): void
    {
        Log::shouldReceive('error')->once()->with(
            'Broadcast job exhausted retries — a live UI update was dropped',
            Mockery::on(fn (array $context) => $context['queue'] === 'default'),
        );

        $this->fire(BroadcastEvent::class, 'default');
    }
}
