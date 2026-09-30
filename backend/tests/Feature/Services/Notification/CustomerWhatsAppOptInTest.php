<?php

namespace Tests\Feature\Services\Notification;

use App\Jobs\SendCustomerWhatsAppJob;
use App\Models\AdminUser;
use App\Models\CustomerNotification;
use App\Models\Order;
use App\Models\PlatformSettings;
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
 * ADR-116 PR-B2: opt-in per phone from the customer-support number, STOP,
 * and Delivered receipts.
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
        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'PG-'.Str::upper(Str::random(10)),
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Aina',
            'customer_phone' => '0123456789',
            'player_id' => '51049607',
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

    public function test_the_updates_button_on_a_delivered_order_opts_in_and_sends_the_receipt_now(): void
    {
        $order = $this->order();

        $this->inbound("Salam, saya nak terima update order {$order->order_number} di WhatsApp.");

        $contact = WhatsappContact::query()->sole();
        $this->assertSame(self::ORDER_PHONE, $contact->phone);
        $this->assertSame('updates', $contact->opt_in_source);
        $receipt = CustomerNotification::query()->sole();
        $this->assertSame('delivered_receipt', $receipt->event);
        $this->assertSame(self::ORDER_PHONE, $receipt->phone);
        $this->assertStringContainsString($order->order_number, $receipt->message);
        $this->assertStringContainsString('Player ID 51049607', $receipt->message);
        $this->assertStringContainsString('RM10.90', $receipt->message);
        $this->assertStringContainsString('Reply STOP', $receipt->message);
        Queue::assertPushedOn('whatsapp', SendCustomerWhatsAppJob::class);
    }

    public function test_the_updates_button_on_an_undelivered_order_promises_a_message_later(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::Processing->value]);

        $this->inbound("Salam, saya nak terima update order {$order->order_number} di WhatsApp.");

        $reply = CustomerNotification::query()->sole();
        $this->assertSame('optin_reply', $reply->event);
        $this->assertStringContainsString("We'll message you here", $reply->message);
    }

    public function test_the_receipt_goes_only_to_the_checkout_number_never_to_whoever_knows_the_order_number(): void
    {
        $order = $this->order();

        $this->inbound("update order {$order->order_number}", '60199999999');

        $reply = CustomerNotification::query()->sole();
        $this->assertSame('optin_reply', $reply->event);
        $this->assertSame('60199999999', $reply->phone);
        $this->assertStringContainsString('phone number used at checkout', $reply->message);
        $this->assertStringNotContainsString('51049607', $reply->message);
    }

    public function test_a_support_message_opts_in_silently_so_staff_take_the_chat(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value]);

        $this->inbound("Salam support PekanGame, saya perlukan bantuan untuk order {$order->order_number} (MLBB).");

        $this->assertSame('support', WhatsappContact::query()->sole()->opt_in_source);
        $this->assertSame(0, CustomerNotification::query()->count());
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
    }

    public function test_a_delivery_to_a_number_that_never_opted_in_sends_nothing(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::NeedsReview->value]);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->postJson("/api/orders/{$order->id}/mark-delivered", ['supplier_ref' => 'X1', 'note' => 'confirmed'])->assertOk();

        $this->assertSame(0, CustomerNotification::query()->count());
        Queue::assertNotPushed(SendCustomerWhatsAppJob::class);
    }

    public function test_stop_ends_receipts_and_only_the_updates_button_undoes_it(): void
    {
        $order = $this->order();
        $this->inbound("Salam support, order {$order->order_number}");

        $this->inbound('stop');
        $this->assertNotNull(WhatsappContact::query()->sole()->opted_out_at);
        $this->assertSame('stop_reply', CustomerNotification::query()->sole()->event);

        // A support chat after STOP doesn't opt back in...
        $this->inbound("Salam support, order {$order->order_number}");
        $this->assertNotNull(WhatsappContact::query()->sole()->opted_out_at);

        // ...the explicit updates button does.
        $this->inbound("update order {$order->order_number}");
        $this->assertNull(WhatsappContact::query()->sole()->opted_out_at);
    }

    public function test_group_chats_and_our_own_echoes_are_ignored(): void
    {
        $order = $this->order();

        $this->inbound("update order {$order->order_number}", data: ['kind' => 'group', 'from' => 'g1@g.us']);
        $this->inbound("update order {$order->order_number}", data: ['fromMe' => true]);

        $this->assertSame(0, WhatsappContact::query()->count());
        $this->assertSame(0, CustomerNotification::query()->count());
    }

    public function test_a_privacy_id_sender_uses_openwa_sender_phone(): void
    {
        $order = $this->order();

        $this->inbound("update order {$order->order_number}", data: ['from' => '12345@lid', 'senderPhone' => self::ORDER_PHONE]);

        $this->assertSame('delivered_receipt', CustomerNotification::query()->sole()->event);
    }
}
