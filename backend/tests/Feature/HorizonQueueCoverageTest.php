<?php

namespace Tests\Feature;

use App\Jobs\Reseller\SendResellerBotReplyJob;
use App\Jobs\SendMembershipReceiptJob;
use App\Listeners\Reseller\SendResellerBotOrderNotification;
use App\Services\OpenWa\OpenWaClient;
use Illuminate\Support\Arr;
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

    public function test_send_membership_receipt_job_lands_on_a_supervised_queue(): void
    {
        $queue = (new SendMembershipReceiptJob(1, 'subscription', 100))->queue ?? 'default';

        $this->assertContains($queue, $this->supervisedQueues());
    }

    public function test_reseller_bot_order_notification_is_pinned_to_the_orders_queue(): void
    {
        $listener = new SendResellerBotOrderNotification(app(OpenWaClient::class));

        $this->assertSame('orders', $listener->queue);
        $this->assertContains($listener->queue, $this->supervisedQueues());
    }

    /** E8 hardening: OpenWaClient::sendText() now dispatches this job instead of calling out inline. */
    public function test_send_reseller_bot_reply_job_is_pinned_to_the_orders_queue(): void
    {
        $job = new SendResellerBotReplyJob('chat-1', 'hello');

        $this->assertSame('orders', $job->queue);
        $this->assertContains($job->queue, $this->supervisedQueues());
    }
}
