<?php

namespace Tests\Feature\Services\Notification;

use App\Jobs\SendCustomerWhatsAppJob;
use App\Models\AdminUser;
use App\Models\CustomerNotification;
use App\Models\Order;
use App\Models\PlatformSettings;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Models\WhatsappContact;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-116 PR-B2 plus its 2026-09-30 addendum: an order number in any message is
 * the opt-in and gets the order's status card back; STOP/START; Delivered
 * receipts; a skipped message gets revived.
 */
class CustomerWhatsAppOptInTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER_PHONE = '60123456789';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openwa.webhook_secret' => 'test-secret',
            'services.openwa.session_id' => 'bot-session',
            'services.openwa.cs_session_id' => 'cs-session',
            'services.openwa.cs_api_key' => 'cs-key',
        ]);
        PlatformSettings::current()->update(['whatsapp_notifications_enabled' => true]);
        Queue::fake();
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'PG-'.Str::upper(Str::random(10)),
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Aina',
            'customer_phone' => '0123456789',
            'player_id' => '51049607',
            'server_id' => '2005',
            'payment_method' => 'fpx',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 90,
            'final_amount' => 1090,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
        ], $overrides));
    }

    /** A direct message to the customer-support number, as OpenWA delivers it. */
    private function inbound(string $body, string $from = self::ORDER_PHONE, array $data = []): TestResponse
    {
        $payload = json_encode([
            'event' => 'message.received',
            'sessionId' => 'cs-session',
            'data' => $data + ['kind' => 'individual', 'from' => $from.'@c.us', 'body' => $body, 'id' => (string) Str::uuid(), 'fromMe' => false],
        ]);

        return $this->call('POST', '/api/webhooks/openwa', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_OPENWA_SIGNATURE' => 'sha256='.hash_hmac('sha256', $payload, 'test-secret'),
        ], $payload)->assertOk();
    }

    public function test_the_support_button_message_opts_in_and_gets_the_status_card(): void
    {
        $order = $this->order();

        $this->inbound("Salam support PekanGame, saya perlukan bantuan untuk order {$order->order_number} (Mobile Legends Malaysia).");

        $this->assertSame('message', WhatsappContact::query()->sole()->opt_in_source);
        $card = CustomerNotification::query()->sole();
        $this->assertSame('status_card', $card->event);
        $this->assertSame(self::ORDER_PHONE, $card->phone);
        foreach (["*PekanGame* · Order {$order->order_number}", '✅ Paid · RM10.90 via FPX', '✅ Processed', '✅ Delivered to Player ID 51049607 (2005)', "/order/status/{$order->order_number}"] as $line) {
            $this->assertStringContainsString($line, $card->message);
        }
        $this->assertStringNotContainsString('buyer@example.com', $card->message);
        Queue::assertPushedOn('whatsapp', SendCustomerWhatsAppJob::class);
    }

    public function test_the_card_is_honest_about_a_processing_order(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::Processing->value]);

        $this->inbound("Salam, saya nak terima update order {$order->order_number} di WhatsApp.");

        $message = CustomerNotification::query()->sole()->message;
        $this->assertStringContainsString('⏳ Processing', $message);
        $this->assertStringContainsString('○ Delivery to Player ID', $message);
        $this->assertStringContainsString("We'll message you here once it's delivered", $message);
    }

    public function test_the_card_is_honest_about_a_failed_order(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value]);

        $this->inbound("tolong update order {$order->order_number}");

        $message = CustomerNotification::query()->sole()->message;
        $this->assertStringContainsString('❌ Delivery failed', $message);
        $this->assertStringContainsString('voucher for the full amount', $message);
    }

    public function test_the_same_card_is_not_repeated_within_30_minutes_unless_the_status_changes(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::Processing->value]);

        $this->inbound("order {$order->order_number} dah sampai ke?");
        $this->inbound("hello? order {$order->order_number}");
        $this->assertSame(1, CustomerNotification::query()->count());

        $order->update(['delivery_status' => DeliveryStatus::Delivered->value]);
        $this->inbound("order {$order->order_number}");
        $this->assertSame(2, CustomerNotification::query()->count());

        $this->travel(31)->minutes();
        $this->inbound("order {$order->order_number}");
        $this->assertSame(3, CustomerNotification::query()->count());
    }

    public function test_any_sender_with_the_order_number_gets_the_card_since_it_holds_only_track_page_data(): void
    {
        $order = $this->order();

        $this->inbound("order {$order->order_number}", '60199999999');

        $this->assertSame('60199999999', CustomerNotification::query()->sole()->phone);
        $this->assertSame('60199999999', WhatsappContact::query()->sole()->phone);
    }

    public function test_a_mistyped_order_number_gets_a_not_found_reply_at_most_every_10_minutes(): void
    {
        $this->inbound('order pg-typo12345');
        $this->inbound('sorry, PG-TYPO67890');

        $reply = CustomerNotification::query()->sole();
        $this->assertSame('order_not_found', $reply->event);
        $this->assertStringContainsString("We couldn't find order PG-TYPO12345", $reply->message);
        $this->assertStringContainsString('payment receipt sent to your email', $reply->message);
        $this->assertSame(1, WhatsappContact::query()->count()); // they wrote first: still opted in

        $this->travel(11)->minutes();
        $this->inbound('PG-TYPO67890');
        $this->assertSame(2, CustomerNotification::query()->count());
    }

    public function test_the_awaiting_payment_card_leaves_later_steps_open(): void
    {
        $order = $this->order(['payment_status' => PaymentStatus::Pending->value, 'delivery_status' => DeliveryStatus::NotStarted->value]);

        $this->inbound("order {$order->order_number}");

        $message = CustomerNotification::query()->sole()->message;
        $this->assertStringContainsString('⏳ Awaiting payment · RM10.90', $message);
        $this->assertStringContainsString("We haven't received payment", $message);
    }

    public function test_a_later_order_from_an_opted_in_number_gets_its_receipt_on_delivery(): void
    {
        $first = $this->order();
        $this->inbound("Salam support PekanGame, saya perlukan bantuan untuk order {$first->order_number}.");
        $second = $this->order(['delivery_status' => DeliveryStatus::NeedsReview->value, 'customer_phone' => '+60 12-345 6789']);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        // Admin Mark Delivered: one of the four paths into Delivered, all through creditProfit().
        $this->postJson("/api/orders/{$second->id}/mark-delivered", ['supplier_ref' => 'X1', 'note' => 'confirmed'])->assertOk();

        $receipt = CustomerNotification::query()->where('order_id', $second->id)->sole();
        $this->assertSame('delivered_receipt', $receipt->event);
        $this->assertSame(self::ORDER_PHONE, $receipt->phone);
        $this->assertStringContainsString("Order {$second->order_number}", $receipt->message);
        $this->assertStringContainsString('✅ Delivered to Player ID', $receipt->message);
        $this->assertStringContainsString('Reply STOP', $receipt->message);
    }

    public function test_a_delivery_to_a_number_that_never_opted_in_sends_nothing(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::NeedsReview->value]);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->postJson("/api/orders/{$order->id}/mark-delivered", ['supplier_ref' => 'X1', 'note' => 'confirmed'])->assertOk();

        $this->assertSame(0, CustomerNotification::query()->count());
        Queue::assertNotPushed(SendCustomerWhatsAppJob::class);
    }

    public function test_stop_is_undone_only_by_start_never_by_an_order_number_message(): void
    {
        $order = $this->order();

        $this->inbound('stop');
        $this->assertNotNull(WhatsappContact::query()->sole()->opted_out_at);
        $this->assertSame('stop_reply', CustomerNotification::query()->sole()->event);

        $this->inbound("Salam support, order {$order->order_number}");
        $this->assertNotNull(WhatsappContact::query()->sole()->opted_out_at);

        $this->inbound('START');
        $this->assertNull(WhatsappContact::query()->sole()->opted_out_at);
        $this->assertTrue(CustomerNotification::query()->where('event', 'start_reply')->exists());
    }

    public function test_group_chats_and_our_own_echoes_are_ignored(): void
    {
        $order = $this->order();

        $this->inbound("order {$order->order_number}", data: ['kind' => 'group', 'from' => 'g1@g.us']);
        $this->inbound("order {$order->order_number}", data: ['fromMe' => true]);

        $this->assertSame(0, WhatsappContact::query()->count());
        $this->assertSame(0, CustomerNotification::query()->count());
    }

    public function test_a_privacy_id_sender_uses_openwa_sender_phone(): void
    {
        $order = $this->order();

        $this->inbound("order {$order->order_number}", data: ['from' => '12345@lid', 'senderPhone' => self::ORDER_PHONE]);

        $this->assertSame(self::ORDER_PHONE, CustomerNotification::query()->sole()->phone);
    }

    /** Found in the first live test: a message skipped while the switch was off could never be sent later. */
    public function test_a_message_skipped_while_the_switch_was_off_goes_out_once_it_is_on(): void
    {
        $original = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id, 'code' => 'PG-FULLCOVER', 'customer_email' => 'buyer@example.com',
            'amount' => 1000, 'remaining' => 0, 'status' => 'exhausted', 'reason' => 'earlier compensation',
        ]);
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value, 'voucher_id' => $original->id, 'voucher_discount' => 1000, 'transaction_fee' => 0, 'final_amount' => 0]);
        VoucherRedemption::query()->create(['voucher_id' => $original->id, 'order_id' => $order->id, 'amount' => 1000, 'status' => 'reserved']);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        PlatformSettings::current()->update(['whatsapp_notifications_enabled' => false]);
        $this->postJson("/api/orders/{$order->id}/voucher")->assertCreated();
        $this->assertSame('skipped', CustomerNotification::query()->sole()->status);

        PlatformSettings::current()->update(['whatsapp_notifications_enabled' => true]);
        $this->postJson("/api/orders/{$order->id}/voucher")->assertCreated();
        $this->postJson("/api/orders/{$order->id}/voucher")->assertCreated();

        $notification = CustomerNotification::query()->sole();
        $this->assertSame('queued', $notification->status);
        $this->assertNull($notification->error);
        Queue::assertPushed(SendCustomerWhatsAppJob::class, 1); // revived once, never twice
    }
}
