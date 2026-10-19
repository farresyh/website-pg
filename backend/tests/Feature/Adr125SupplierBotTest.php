<?php

namespace Tests\Feature;

use App\Jobs\Reseller\SendResellerBotReplyJob;
use App\Models\Order;
use App\Models\OrderResendAttempt;
use App\Models\Supplier;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppPendingLink;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Order\DeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrderCredentialFixtures;
use Tests\Support\OrderFulfillmentFixtures;
use Tests\TestCase;

/**
 * ADR-125 decisions 11/14/57/58: a supplier group's `.done` / `.gagal` /
 * `.pending`, routed by the webhook to the supplier bot (never the reseller
 * bot), acting only on that supplier's own Pending orders, at the agreed
 * cost, with the WhatsApp sender recorded.
 */
class Adr125SupplierBotTest extends TestCase
{
    use OrderCredentialFixtures;
    use OrderFulfillmentFixtures;
    use RefreshDatabase;

    private const GROUP = 'supplier-a@g.us';

    private Supplier $manual;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.openwa.webhook_secret' => 'test-secret',
            'services.openwa.webhook_signature_header' => 'X-OpenWA-Signature',
            'services.openwa.webhook_signature_algo' => 'sha256',
            'services.openwa.session_id' => 'bot-session',
            'services.openwa.api_key' => 'bot-key',
        ]);
        Queue::fake();
        $this->manual = Supplier::query()->create([
            'name' => 'Roblox A', 'slug' => 'roblox-a', 'driver' => Supplier::DRIVER_MANUAL, 'api_config' => [], 'currency' => 'MYR',
        ]);
        WhatsAppGroup::query()->create(['owner_type' => 'supplier', 'owner_id' => $this->manual->id, 'whatsapp_group_id' => self::GROUP]);
    }

    private function pendingOrder(string $number = 'PG-AAAAAAAAAAAA', ?Supplier $supplier = null): Order
    {
        $order = $this->paidOrder(['order_number' => $number, 'supplier_id' => ($supplier ?? $this->manual)->id, 'server_id' => null, 'supplier_product_ref' => 'MANUAL-1']);
        $this->credentialFor($order);

        return app(OrderFulfillmentService::class)->fulfill($order);
    }

    private function say(string $text, array $data = []): TestResponse
    {
        $body = json_encode([
            'event' => 'message.received',
            'sessionId' => 'bot-session',
            'data' => $data + ['kind' => 'group', 'chatId' => self::GROUP, 'body' => $text, 'id' => uniqid('m'), 'author' => '60123456789@c.us', 'fromMe' => false],
        ]);

        return $this->call('POST', '/api/webhooks/openwa', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_OPENWA_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'test-secret'),
        ], $body)->assertOk();
    }

    /** @return list<string> */
    private function replies(): array
    {
        $texts = [];
        Queue::assertPushed(SendResellerBotReplyJob::class, function (SendResellerBotReplyJob $job) use (&$texts) {
            $texts[] = $job->text;

            return true;
        });

        return $texts;
    }

    public function test_done_delivers_at_the_agreed_cost_and_records_the_sender(): void
    {
        $order = $this->pendingOrder();

        $this->say('.done pg-aaaaaaaaaaaa');

        $order->refresh();
        $this->assertSame(DeliveryStatus::Delivered, $order->delivery_status);
        $this->assertSame(900, $order->real_cost_price_sen);
        $this->assertSame('wa:60123456789', $order->supplier_response['confirmed_by']);
        $this->assertSame('supplier_bot', $order->supplier_response['channel']);
        $this->assertSame('wa:60123456789', OrderResendAttempt::query()->where('attempt_type', 'manual_confirm')->sole()->triggered_by);
        $this->assertStringContainsString('ditanda SELESAI', $this->replies()[0]);
    }

    public function test_a_privacy_id_sender_is_recorded_by_its_resolved_phone(): void
    {
        $order = $this->pendingOrder();

        $this->say('.done PG-AAAAAAAAAAAA', ['author' => '1234567890@lid', 'senderPhone' => '60199999999']);

        $this->assertSame('wa:60199999999', $order->fresh()->supplier_response['confirmed_by']);
    }

    public function test_gagal_needs_a_reason_then_fails_the_order(): void
    {
        $order = $this->pendingOrder();

        $this->say('.gagal PG-AAAAAAAAAAAA');
        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);

        $this->say('.gagal PG-AAAAAAAAAAAA password salah');
        $order->refresh();
        $this->assertSame(DeliveryStatus::Failed, $order->delivery_status);
        $this->assertSame('password salah', $order->supplier_response['reason']);

        [$askReason, $failed] = $this->replies();
        $this->assertStringContainsString('Sila beri sebab', $askReason);
        $this->assertStringContainsString('ditanda GAGAL', $failed);
    }

    public function test_done_on_a_failed_order_points_to_the_admin(): void
    {
        $order = $this->pendingOrder();
        $this->say('.gagal PG-AAAAAAAAAAAA password salah');

        $this->say('.done PG-AAAAAAAAAAAA');

        $this->assertSame(DeliveryStatus::Failed, $order->fresh()->delivery_status);
        $this->assertStringContainsString('hubungi admin', $this->replies()[1]);
    }

    public function test_another_suppliers_order_is_never_touched(): void
    {
        $other = Supplier::query()->create(['name' => 'Roblox B', 'slug' => 'roblox-b', 'driver' => Supplier::DRIVER_MANUAL, 'api_config' => [], 'currency' => 'MYR']);
        $order = $this->pendingOrder('PG-BBBBBBBBBBBB', $other);

        $this->say('.done PG-BBBBBBBBBBBB');

        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
        $this->assertStringContainsString('tiada dalam senarai anda', $this->replies()[0]);
    }

    public function test_pending_lists_only_this_suppliers_waiting_orders_without_a_login(): void
    {
        $this->pendingOrder('PG-AAAAAAAAAAAA');
        $other = Supplier::query()->create(['name' => 'Roblox B', 'slug' => 'roblox-b', 'driver' => Supplier::DRIVER_MANUAL, 'api_config' => [], 'currency' => 'MYR']);
        $this->pendingOrder('PG-BBBBBBBBBBBB', $other);

        $this->say('.pending');

        $reply = $this->replies()[0];
        $this->assertStringContainsString('PG-AAAAAAAAAAAA', $reply);
        $this->assertStringNotContainsString('PG-BBBBBBBBBBBB', $reply);
        $this->assertStringNotContainsString('player_one', $reply);
    }

    public function test_plain_chat_is_silent_and_an_unknown_command_gets_help(): void
    {
        $this->say('ok bos, kejap');
        Queue::assertNotPushed(SendResellerBotReplyJob::class);

        $this->say('.baki');
        $this->assertStringContainsString('.done PG-XXXX', $this->replies()[0]);
    }

    /** Decision 57: the bot's own post (it lists the commands) is never parsed. */
    public function test_the_bots_own_message_is_ignored(): void
    {
        $order = $this->pendingOrder();

        $this->say('.done PG-AAAAAAAAAAAA', ['fromMe' => true]);

        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
        Queue::assertNotPushed(SendResellerBotReplyJob::class);
    }

    public function test_an_unlinked_group_is_still_captured_for_linking(): void
    {
        $this->say('hello', ['chatId' => 'new-group@g.us']);

        $this->assertTrue(WhatsAppPendingLink::query()->where('whatsapp_group_id', 'new-group@g.us')->exists());
    }
}
