<?php

namespace Tests\Feature\Services\Notification;

use App\Jobs\SendCustomerWhatsAppJob;
use App\Models\AdminUser;
use App\Models\AffiliateBranding;
use App\Models\CustomerNotification;
use App\Models\Order;
use App\Models\PlatformSettings;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Models\WhatsappContact;
use App\Services\Notification\CustomerNotificationService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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
        // The platform brand's store name, as on production; without it the name falls back to business_name.
        AffiliateBranding::withoutAffiliateScope()->create(['affiliate_id' => $this->primaryAffiliate()->id, 'store_name' => 'PekanGame']);
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
        // Item 116: the voucher is final_amount − transaction_fee, never the full amount.
        $this->assertStringContainsString('excluding the payment fee', $message);
        $this->assertStringNotContainsString('full amount', $message);
    }

    public function test_every_message_with_the_order_number_gets_a_card_even_when_nothing_changed(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::Processing->value]);

        $this->inbound("order {$order->order_number} dah sampai ke?");
        $this->inbound("hello? order {$order->order_number}");
        $this->inbound("Salam, saya nak resit order {$order->order_number} di WhatsApp.");

        $this->assertSame(3, CustomerNotification::query()->where('event', 'status_card')->where('status', 'queued')->count());
        Queue::assertPushed(SendCustomerWhatsAppJob::class, 3);
    }

    public function test_a_redelivered_webhook_for_the_same_message_is_answered_once(): void
    {
        $order = $this->order();

        $this->inbound("order {$order->order_number}", data: ['id' => 'wamid-1']);
        $this->inbound("order {$order->order_number}", data: ['id' => 'wamid-1']);

        $this->assertSame(1, CustomerNotification::query()->count());
        Queue::assertPushed(SendCustomerWhatsAppJob::class, 1);
    }

    public function test_any_sender_with_the_order_number_gets_the_card_since_it_holds_only_track_page_data(): void
    {
        $order = $this->order();

        $this->inbound("order {$order->order_number}", '60199999999');

        $this->assertSame('60199999999', CustomerNotification::query()->sole()->phone);
        $this->assertSame('60199999999', WhatsappContact::query()->sole()->phone);
    }

    public function test_every_mistyped_order_number_gets_a_not_found_reply(): void
    {
        $this->inbound('order pg-typo12345');
        $this->inbound('sorry, PG-TYPO67890');

        $replies = CustomerNotification::query()->oldest('id')->get();
        $this->assertCount(2, $replies);
        $this->assertSame(['order_not_found', 'order_not_found'], $replies->pluck('event')->all());
        $this->assertStringContainsString("We couldn't find order PG-TYPO12345", $replies[0]->message);
        $this->assertStringContainsString("We couldn't find order PG-TYPO67890", $replies[1]->message);
        $this->assertStringContainsString('payment receipt sent to your email', $replies[0]->message);
        $this->assertSame(1, WhatsappContact::query()->count()); // they wrote first: still opted in
    }

    public function test_the_awaiting_payment_card_leaves_later_steps_open(): void
    {
        $order = $this->order(['payment_status' => PaymentStatus::Pending->value, 'delivery_status' => DeliveryStatus::NotStarted->value]);

        $this->inbound("order {$order->order_number}");

        $message = CustomerNotification::query()->sole()->message;
        $this->assertStringContainsString('⏳ Awaiting payment · RM10.90', $message);
        $this->assertStringContainsString("We haven't received payment", $message);
    }

    /**
     * Production, 2026-10-30 (PG-L4RCBELJ9RE3): the customer typed their mobile without the leading 0, so the order
     * held 1123456267 while WhatsApp wrote from 601123456267. The opt-in was recorded under the second, the receipt
     * looked for the first, found no opt-in and stayed silent after a Resend.
     */
    public function test_a_number_typed_without_the_leading_zero_still_gets_its_receipt_after_a_resend(): void
    {
        $order = $this->order([
            'customer_phone' => '1123456267', 'delivery_status' => DeliveryStatus::Failed->value, 'supplier_id' => null,
        ]);
        $this->inbound("Salam support, order {$order->order_number} gagal", from: '601123456267');
        $this->assertSame('601123456267', WhatsappContact::query()->sole()->phone);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        $order->update(['delivery_status' => DeliveryStatus::NeedsReview->value]);

        $this->postJson("/api/orders/{$order->id}/mark-delivered", ['supplier_ref' => 'X1', 'supplier_price' => 4.75, 'note' => 'resent'])->assertOk();

        $receipt = CustomerNotification::query()->where('order_id', $order->id)->where('event', 'delivered_receipt')->sole();
        $this->assertSame('601123456267', $receipt->phone);
    }

    public function test_a_later_order_from_an_opted_in_number_gets_its_receipt_on_delivery(): void
    {
        $first = $this->order();
        $this->inbound("Salam support PekanGame, saya perlukan bantuan untuk order {$first->order_number}.");
        $second = $this->order(['delivery_status' => DeliveryStatus::NeedsReview->value, 'customer_phone' => '+60 12-345 6789']);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        // Admin Mark Delivered: one of the four paths into Delivered, all through creditProfit().
        $this->postJson("/api/orders/{$second->id}/mark-delivered", ['supplier_ref' => 'X1', 'supplier_price' => 4.75, 'note' => 'confirmed'])->assertOk();

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

        $this->postJson("/api/orders/{$order->id}/mark-delivered", ['supplier_ref' => 'X1', 'supplier_price' => 4.75, 'note' => 'confirmed'])->assertOk();

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

    private const LID_CHAT = '234767895240796@lid';

    private function markDelivered(Order $order): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        $order->update(['delivery_status' => DeliveryStatus::NeedsReview->value]);
        $this->postJson("/api/orders/{$order->id}/mark-delivered", ['supplier_ref' => 'X1', 'supplier_price' => 4.75, 'note' => 'resent'])->assertOk();
    }

    /** @return Collection<int, CustomerNotification> */
    private function receipts(Order $order)
    {
        return CustomerNotification::query()->where('order_id', $order->id)->where('event', 'delivered_receipt')->get();
    }

    /** ADR-116 2026-11-01 addendum, found on PG-WSEZAO6R4TGU: a privacy id with no resolvable phone was ignored. */
    public function test_a_privacy_id_with_no_phone_gets_the_status_card_in_its_own_chat(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value]);

        $this->inbound($order->order_number, data: ['from' => self::LID_CHAT, 'chatId' => self::LID_CHAT]);

        $card = CustomerNotification::query()->sole();
        $this->assertSame('status_card', $card->event);
        $this->assertSame(self::LID_CHAT, $card->chat_id);
        $this->assertNull($card->phone);
        $this->assertSame('queued', $card->status);
        $this->assertSame(self::LID_CHAT, WhatsappContact::query()->sole()->chat_id);
    }

    public function test_a_privacy_id_that_asked_about_the_order_gets_its_receipt(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value, 'supplier_id' => null]);
        $this->inbound("order {$order->order_number}", data: ['from' => self::LID_CHAT]);

        $this->markDelivered($order);

        $receipt = $this->receipts($order)->sole();
        $this->assertSame(self::LID_CHAT, $receipt->chat_id);
        $this->assertStringContainsString('Reply STOP', $receipt->message);
    }

    public function test_a_different_number_that_asked_about_the_order_gets_its_receipt(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value, 'supplier_id' => null]);
        $this->inbound("order {$order->order_number}", from: '60199998888');

        $this->markDelivered($order);

        $this->assertSame('60199998888', $this->receipts($order)->sole()->phone);
    }

    public function test_every_follower_gets_one_receipt_and_a_replayed_event_sends_none_again(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value, 'supplier_id' => null]);
        $this->inbound("order {$order->order_number}", data: ['from' => self::LID_CHAT]);
        $this->inbound("order {$order->order_number}", data: ['from' => self::LID_CHAT]);
        $this->inbound("order {$order->order_number}", from: '60199998888');

        $this->markDelivered($order);
        app(CustomerNotificationService::class)->orderDelivered($order->id);

        $receipts = $this->receipts($order);
        $this->assertCount(2, $receipts);
        $this->assertEqualsCanonicalizing([self::LID_CHAT, '60199998888'], $receipts->map(fn ($r) => $r->chat_id ?? $r->phone)->all());
    }

    public function test_an_opted_in_checkout_number_alone_gets_the_receipt_so_nobody_gets_it_twice(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value, 'supplier_id' => null]);
        $this->inbound('Hi');
        $this->inbound("order {$order->order_number}");
        $this->inbound("order {$order->order_number}", data: ['from' => self::LID_CHAT]);

        $this->markDelivered($order);

        $this->assertSame(self::ORDER_PHONE, $this->receipts($order)->sole()->phone);
    }

    /** Stress-test finding L1: the checkout number's STOP must not be bypassed through a follower chat. */
    public function test_a_checkout_number_that_said_stop_silences_every_follower(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value, 'supplier_id' => null]);
        $this->inbound('STOP');
        $this->inbound("order {$order->order_number}", data: ['from' => self::LID_CHAT]);

        $this->markDelivered($order);

        $this->assertCount(0, $this->receipts($order));
    }

    public function test_stop_and_start_work_for_a_privacy_id(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value, 'supplier_id' => null]);
        $this->inbound("order {$order->order_number}", data: ['from' => self::LID_CHAT]);
        $this->inbound('stop', data: ['from' => self::LID_CHAT]);

        $stop = CustomerNotification::query()->where('event', 'stop_reply')->sole();
        $this->assertSame(self::LID_CHAT, $stop->chat_id);
        $this->markDelivered($order);
        $this->assertCount(0, $this->receipts($order));

        $this->inbound('START', data: ['from' => self::LID_CHAT]);
        $this->assertNull(WhatsappContact::query()->sole()->opted_out_at);
    }

    /** Stress-test finding L2: one person seen first as a privacy id, later with a resolved phone. STOP wins. */
    public function test_a_privacy_id_that_said_stop_keeps_the_stop_once_its_phone_resolves(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value, 'supplier_id' => null]);
        $this->inbound('STOP', data: ['from' => self::LID_CHAT]);
        $this->inbound("order {$order->order_number}", data: ['from' => self::LID_CHAT, 'senderPhone' => self::ORDER_PHONE]);

        $contact = WhatsappContact::query()->sole();
        $this->assertSame(self::ORDER_PHONE, $contact->phone);
        $this->assertSame(self::LID_CHAT, $contact->chat_id);

        $this->markDelivered($order);
        $this->assertCount(0, $this->receipts($order));
    }

    public function test_two_rows_for_one_person_mean_stop_wins(): void
    {
        WhatsappContact::query()->create(['phone' => self::ORDER_PHONE, 'opted_in_at' => now(), 'opt_in_source' => 'message']);
        WhatsappContact::query()->create(['chat_id' => self::LID_CHAT, 'opted_in_at' => now(), 'opt_in_source' => 'message', 'opted_out_at' => now()]);

        $this->assertFalse(WhatsappContact::receivesReceipts(self::ORDER_PHONE, self::LID_CHAT));
        $this->assertTrue(WhatsappContact::receivesReceipts(self::ORDER_PHONE, null));
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
