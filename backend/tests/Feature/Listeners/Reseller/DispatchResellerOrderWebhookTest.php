<?php

namespace Tests\Feature\Listeners\Reseller;

use App\Events\OrderStatusUpdated;
use App\Jobs\Reseller\DeliverResellerWebhook;
use App\Listeners\Reseller\DispatchResellerOrderWebhook;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerWebhookDelivery;
use App\Services\Order\DeliveryStatus;
use App\Services\Reseller\Webhook\ResellerWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ADR-084 PR-3 decision 4: a wallet order reaching delivered/failed
 * queues one `order.delivered` / `order.failed` webhook — via the same
 * `OrderStatusUpdated` seam the Bot notification rides.
 */
class DispatchResellerOrderWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function resellerWithWebhook(bool $active = true): Reseller
    {
        $reseller = Reseller::query()->create(['business_name' => 'Wallet Reseller', 'is_active' => true]);
        $result = app(ResellerWebhookService::class)->setEndpoint($reseller, 'https://example.test/hook');
        if (! $active) {
            app(ResellerWebhookService::class)->setActive($result['webhook'], false);
        }

        return $reseller->fresh();
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'PG-'.uniqid(),
            'customer_email' => 'buyer@example.test',
            'player_id' => '123456',
            'cost_price' => 900, 'standard_selling_price' => 900, 'selling_price' => 1000,
            'transaction_fee' => 0, 'final_amount' => 1000,
            'platform_profit' => 100, 'affiliate_profit' => 0,
            'payment_status' => 'paid',
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ], $overrides));
    }

    private function fire(Order $order): void
    {
        app(DispatchResellerOrderWebhook::class)->handle(new OrderStatusUpdated($order));
    }

    public function test_delivered_wallet_order_creates_a_delivery_row_and_queues_the_job(): void
    {
        Queue::fake();
        $reseller = $this->resellerWithWebhook();
        $order = $this->order(['wallet_reseller_id' => $reseller->id, 'delivery_status' => DeliveryStatus::Delivered->value]);

        $this->fire($order);

        $delivery = ResellerWebhookDelivery::query()->where('order_id', $order->id)->sole();
        $this->assertSame('order.delivered', $delivery->event);
        $this->assertSame('pending', $delivery->status);
        $this->assertSame($reseller->id, $delivery->reseller_id);
        $this->assertSame(1000, $delivery->payload['price_sen']);
        $this->assertSame($order->order_number, $delivery->payload['order_number']);
        Queue::assertPushed(DeliverResellerWebhook::class, fn ($job) => $job->deliveryId === $delivery->id);
    }

    public function test_failed_wallet_order_creates_an_order_failed_row(): void
    {
        Queue::fake();
        $reseller = $this->resellerWithWebhook();
        $order = $this->order(['wallet_reseller_id' => $reseller->id, 'delivery_status' => DeliveryStatus::Failed->value]);

        $this->fire($order);

        $this->assertSame('order.failed', ResellerWebhookDelivery::query()->where('order_id', $order->id)->value('event'));
    }

    public function test_needs_review_does_not_fire(): void
    {
        Queue::fake();
        $reseller = $this->resellerWithWebhook();
        $order = $this->order(['wallet_reseller_id' => $reseller->id, 'delivery_status' => DeliveryStatus::NeedsReview->value]);

        $this->fire($order);

        $this->assertSame(0, ResellerWebhookDelivery::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_non_wallet_order_does_not_fire(): void
    {
        Queue::fake();
        $order = $this->order(['delivery_status' => DeliveryStatus::Delivered->value]);

        $this->fire($order);

        $this->assertSame(0, ResellerWebhookDelivery::query()->count());
    }

    public function test_reseller_without_a_webhook_does_not_fire(): void
    {
        Queue::fake();
        $reseller = Reseller::query()->create(['business_name' => 'No Hook', 'is_active' => true]);
        $order = $this->order(['wallet_reseller_id' => $reseller->id, 'delivery_status' => DeliveryStatus::Delivered->value]);

        $this->fire($order);

        $this->assertSame(0, ResellerWebhookDelivery::query()->count());
    }

    public function test_inactive_webhook_does_not_fire(): void
    {
        Queue::fake();
        $reseller = $this->resellerWithWebhook(active: false);
        $order = $this->order(['wallet_reseller_id' => $reseller->id, 'delivery_status' => DeliveryStatus::Delivered->value]);

        $this->fire($order);

        $this->assertSame(0, ResellerWebhookDelivery::query()->count());
    }

    public function test_the_same_event_is_never_dispatched_twice_for_one_order(): void
    {
        Queue::fake();
        $reseller = $this->resellerWithWebhook();
        $order = $this->order(['wallet_reseller_id' => $reseller->id, 'delivery_status' => DeliveryStatus::Delivered->value]);

        $this->fire($order);
        $this->fire($order->fresh());

        $this->assertSame(1, ResellerWebhookDelivery::query()->where('order_id', $order->id)->count());
        Queue::assertPushed(DeliverResellerWebhook::class, 1);
    }

    public function test_is_wired_to_the_real_order_status_updated_broadcast(): void
    {
        // No Queue::fake() here — the listener is ShouldQueue, so it must
        // run through the sync queue (phpunit.xml QUEUE_CONNECTION=sync)
        // for this to prove the Event::listen() registration. Http::fake()
        // catches the DeliverResellerWebhook POST that then runs inline.
        Http::fake();
        $reseller = $this->resellerWithWebhook();
        $order = $this->order(['wallet_reseller_id' => $reseller->id, 'delivery_status' => DeliveryStatus::Processing->value]);

        $order->update(['delivery_status' => DeliveryStatus::Delivered->value]);

        $delivery = ResellerWebhookDelivery::query()->where('order_id', $order->id)->sole();
        $this->assertSame('delivered', $delivery->status);
        Http::assertSent(fn ($request) => $request->url() === 'https://example.test/hook'
            && $request->hasHeader('X-Hub-Signature-256'));
    }
}
