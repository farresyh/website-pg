<?php

namespace Tests\Feature\Listeners\Reseller;

use App\Events\OrderStatusUpdated;
use App\Listeners\Reseller\SendResellerBotOrderNotification;
use App\Models\Order;
use App\Models\ResellerBotOrderNotification;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-076 decisions 4/5 — message 2 of the Bot channel's two-stage
 * order messaging. Configures `services.openwa.*` per-test so
 * `OpenWaClient::sendText()` (unconfigured/no-op everywhere else in
 * the test suite) actually attempts the real HTTP call, catchable via
 * `Http::fake()` — the only way to observe whether this listener sent
 * anything, since it has no other side effect.
 */
class SendResellerBotOrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function configureOpenWa(): void
    {
        config(['services.openwa.session_id' => 'test-session', 'services.openwa.api_key' => 'test-key']);
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'PG-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ], $overrides));
    }

    public function test_sends_a_message_when_delivery_status_reaches_delivered(): void
    {
        $this->configureOpenWa();
        Http::fake();
        $order = $this->order(['delivery_status' => DeliveryStatus::Delivered->value]);
        $notification = ResellerBotOrderNotification::query()->create([
            'order_id' => $order->id, 'whatsapp_group_id' => 'g1@g.us',
        ]);

        app(SendResellerBotOrderNotification::class)->handle(new OrderStatusUpdated($order));

        Http::assertSentCount(1);
        $this->assertSame('delivered', $notification->fresh()->last_notified_delivery_status);
    }

    public function test_skips_an_intermediate_delivery_status(): void
    {
        $this->configureOpenWa();
        Http::fake();
        $order = $this->order(['delivery_status' => DeliveryStatus::Processing->value]);
        ResellerBotOrderNotification::query()->create(['order_id' => $order->id, 'whatsapp_group_id' => 'g1@g.us']);

        app(SendResellerBotOrderNotification::class)->handle(new OrderStatusUpdated($order));

        Http::assertNothingSent();
    }

    public function test_skips_an_order_with_no_notification_row(): void
    {
        $this->configureOpenWa();
        Http::fake();
        // An order placed via the Reseller API/Portal, not the Bot —
        // no whatsapp_group_id to reply into.
        $order = $this->order(['delivery_status' => DeliveryStatus::Delivered->value]);

        app(SendResellerBotOrderNotification::class)->handle(new OrderStatusUpdated($order));

        Http::assertNothingSent();
    }

    public function test_does_not_resend_for_the_same_terminal_status_twice(): void
    {
        $this->configureOpenWa();
        Http::fake();
        $order = $this->order(['delivery_status' => DeliveryStatus::Delivered->value]);
        ResellerBotOrderNotification::query()->create(['order_id' => $order->id, 'whatsapp_group_id' => 'g1@g.us']);

        app(SendResellerBotOrderNotification::class)->handle(new OrderStatusUpdated($order));
        app(SendResellerBotOrderNotification::class)->handle(new OrderStatusUpdated($order));

        Http::assertSentCount(1);
    }

    public function test_sends_a_fresh_message_when_a_resend_flips_failed_to_delivered(): void
    {
        $this->configureOpenWa();
        Http::fake();
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value]);
        $notification = ResellerBotOrderNotification::query()->create([
            'order_id' => $order->id, 'whatsapp_group_id' => 'g1@g.us',
        ]);

        app(SendResellerBotOrderNotification::class)->handle(new OrderStatusUpdated($order));
        $this->assertSame('failed', $notification->fresh()->last_notified_delivery_status);

        // Admin's Resend Delivery later succeeds.
        $order->update(['delivery_status' => DeliveryStatus::Delivered->value]);
        app(SendResellerBotOrderNotification::class)->handle(new OrderStatusUpdated($order->fresh()));

        Http::assertSentCount(2);
        $this->assertSame('delivered', $notification->fresh()->last_notified_delivery_status);
    }

    /**
     * Every other test above calls `handle()` directly — this one
     * instead goes through the real chain (`Order::update()` →
     * `OrderObserver` → `broadcast()` → `AppServiceProvider`'s
     * `Event::listen()` registration → this listener, queued
     * synchronously since `phpunit.xml` sets `QUEUE_CONNECTION=sync`),
     * to prove the registration itself is wired correctly, not just
     * the listener's own logic.
     */
    public function test_is_actually_wired_to_the_real_order_status_updated_broadcast(): void
    {
        $this->configureOpenWa();
        Http::fake();
        $order = $this->order(['delivery_status' => DeliveryStatus::Processing->value]);
        ResellerBotOrderNotification::query()->create(['order_id' => $order->id, 'whatsapp_group_id' => 'g1@g.us']);

        $order->update(['delivery_status' => DeliveryStatus::Delivered->value]);

        Http::assertSentCount(1);
    }
}
