<?php

namespace Tests\Feature;

use App\Events\BackupRunUpdated;
use App\Events\OrderStatusUpdated;
use App\Events\PriceSyncRunUpdated;
use App\Jobs\Reseller\SendResellerBotReplyJob;
use App\Jobs\SendMembershipReceiptJob;
use App\Jobs\SendMembershipReminderJob;
use App\Listeners\Reseller\SendResellerBotOrderNotification;
use App\Services\Notification\WhatsAppOutbox;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * ADR-077 PR-3 found `SendMembershipReceiptJob` dispatching to the
 * `default` queue with no Horizon supervisor covering it — its receipt
 * emails had silently had no worker in production. This guards against a
 * queued job/listener landing on a queue nothing processes:
 *
 *  1. every queue any Horizon supervisor lists, collected;
 *  2. `default` must be among them (the catch-all for anything that
 *     forgets to name its own queue, plus framework/package jobs);
 *  3. the two classes that rely on that catch-all — or name a specific
 *     queue — resolve to a queue that is actually supervised.
 */
class HorizonQueueCoverageTest extends TestCase
{
    /** @return list<string> */
    private function supervisedQueues(): array
    {
        return collect(config('horizon.defaults'))
            ->flatMap(fn (array $supervisor) => Arr::wrap($supervisor['queue'] ?? []))
            ->unique()
            ->values()
            ->all();
    }

    public function test_a_supervisor_covers_the_default_queue(): void
    {
        $this->assertContains('default', $this->supervisedQueues(), 'no Horizon supervisor processes the `default` queue');
    }

    public function test_every_production_supervisor_is_defined_in_defaults(): void
    {
        $defined = array_keys(config('horizon.defaults'));

        foreach (array_keys(config('horizon.environments.production')) as $name) {
            $this->assertContains($name, $defined, "production lists `{$name}` but it has no `defaults` entry");
        }
    }

    /**
     * 2026-09-29 audit K-4: a job still running when its connection's
     * retry_after lapses is handed to a second worker — a double supplier
     * call once maxProcesses > 1. retry_after is applied by the popping
     * worker's connection, so each supervisor's own connection must
     * outlast its timeout.
     */
    public function test_every_supervisor_timeout_is_shorter_than_its_connection_retry_after(): void
    {
        foreach (config('horizon.defaults') as $name => $supervisor) {
            $retryAfter = config("queue.connections.{$supervisor['connection']}.retry_after");

            $this->assertGreaterThan($supervisor['timeout'], $retryAfter, "`{$name}` timeout {$supervisor['timeout']}s must be < its connection's retry_after");
        }
    }

    /**
     * 2026-11-10: `redis:price-sync` and `redis:backups` never matched, because
     * both supervisors run on `redis-long`. Horizon keys `waits` by
     * connection:queue, so a miss silently falls back to its 60s default:
     * a broadcast queued behind a ~3-minute price sync alerted at 149s.
     * K-4 was the same slip on orders-combo.
     */
    public function test_every_waits_key_names_a_supervised_connection_and_queue(): void
    {
        $lanes = collect(config('horizon.defaults'))
            ->flatMap(fn (array $s) => collect(Arr::wrap($s['queue']))->map(fn (string $q) => "{$s['connection']}:{$q}"))
            ->all();

        foreach (array_keys(config('horizon.waits')) as $key) {
            $this->assertContains($key, $lanes, "horizon.waits `{$key}` matches no supervisor lane, so Horizon uses its 60s default there");
        }
    }

    /**
     * 2026-11-10: a run's status broadcast sat on its own job's one-worker
     * lane (price-sync, backups), so "running" reached the screen only after
     * the ~3-minute run had finished. Every broadcast goes on `default`,
     * which runs no long job.
     *
     * @return array<string, array{class-string}>
     */
    public static function broadcastEvents(): array
    {
        return [
            'order status' => [OrderStatusUpdated::class],
            'price sync run' => [PriceSyncRunUpdated::class],
            'backup run' => [BackupRunUpdated::class],
        ];
    }

    #[DataProvider('broadcastEvents')]
    public function test_every_broadcast_is_queued_on_the_default_lane(string $event): void
    {
        $instance = (new ReflectionClass($event))->newInstanceWithoutConstructor();

        $this->assertSame('default', $instance->broadcastQueue());
    }

    public function test_the_reseller_order_lane_is_supervised(): void
    {
        $this->assertContains('orders-reseller', $this->supervisedQueues());
    }

    public function test_send_membership_receipt_job_lands_on_a_supervised_queue(): void
    {
        $queue = (new SendMembershipReceiptJob(1, 'subscription', 100))->queue ?? 'default';

        $this->assertContains($queue, $this->supervisedQueues());
    }

    public function test_send_membership_reminder_job_lands_on_a_supervised_queue(): void
    {
        $queue = (new SendMembershipReminderJob(1))->queue ?? 'default';

        $this->assertContains($queue, $this->supervisedQueues());
    }

    /** ADR-048 addendum (2026-09-29): notifications moved off the order lanes. */
    public function test_reseller_bot_order_notification_is_pinned_to_the_default_queue(): void
    {
        $listener = new SendResellerBotOrderNotification(app(WhatsAppOutbox::class));

        $this->assertSame('default', $listener->queue);
        $this->assertContains($listener->queue, $this->supervisedQueues());
    }

    /** The retired reply job still drains on `default` for one release (ADR-116 2026-11-14 addendum). */
    public function test_send_reseller_bot_reply_job_is_pinned_to_the_default_queue(): void
    {
        $job = new SendResellerBotReplyJob('chat-1', 'hello');

        $this->assertSame('default', $job->queue);
        $this->assertContains($job->queue, $this->supervisedQueues());
    }

    /** ADR-116 2026-11-14 addendum, decision 6: both outbox lanes have a worker. */
    public function test_both_whatsapp_outbox_lanes_are_supervised(): void
    {
        $this->assertContains(WhatsAppOutbox::LANE_REPLY, $this->supervisedQueues());
        $this->assertContains(WhatsAppOutbox::LANE_PACED, $this->supervisedQueues());
    }
}
