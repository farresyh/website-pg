<?php

namespace Tests\Feature\Services\Notification;

use App\Jobs\VerifyWhatsAppLidJob;
use App\Models\AdminUser;
use App\Models\AffiliateBranding;
use App\Models\CustomerNotification;
use App\Models\Order;
use App\Models\PlatformSettings;
use App\Models\WhatsappContact;
use App\Services\Notification\CustomerNotificationService;
use App\Services\Notification\WhatsAppRecipient;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-116 2026-11-03 addendum (PRD §16 item 156): an `@lid` chat that sends an
 * order number is checked against the checkout phone through OpenWA's lookup,
 * and tied to it on a match.
 */
class CustomerWhatsAppLidTieTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '60123456789';

    private const LID = '234767895240796@lid';

    private const LOOKUP = 'http://openwa.test/api/sessions/cs-session/contacts/60123456789/lid';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openwa.base_url' => 'http://openwa.test',
            'services.openwa.webhook_secret' => 'test-secret',
            'services.openwa.session_id' => 'bot-session',
            'services.openwa.cs_session_id' => 'cs-session',
            'services.openwa.cs_api_key' => 'cs-key',
        ]);
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
            'payment_method' => 'fpx',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 90,
            'final_amount' => 1090,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
            'supplier_id' => null,
        ], $overrides));
    }

    private function inbound(string $body, array $data): void
    {
        $payload = json_encode([
            'event' => 'message.received',
            'sessionId' => 'cs-session',
            'data' => $data + ['kind' => 'individual', 'body' => $body, 'id' => (string) Str::uuid(), 'fromMe' => false],
        ]);

        $this->call('POST', '/api/webhooks/openwa', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_OPENWA_SIGNATURE' => 'sha256='.hash_hmac('sha256', $payload, 'test-secret'),
        ], $payload)->assertOk();
    }

    private function lookupAnswers(?string $lid, int $status = 200): void
    {
        Http::fake([self::LOOKUP => Http::response(['number' => self::PHONE, 'lid' => $lid], $status)]);
    }

    private function runJob(Order $order, string $lid = self::LID): void
    {
        app()->call([new VerifyWhatsAppLidJob($order->id, $lid), 'handle']);
    }

    private function markDelivered(Order $order): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        $order->update(['delivery_status' => DeliveryStatus::NeedsReview->value]);
        $this->postJson("/api/orders/{$order->id}/mark-delivered", ['supplier_ref' => 'X1', 'supplier_price' => 4.75, 'note' => 'resent'])->assertOk();
    }

    public function test_a_privacy_id_sending_an_order_number_queues_the_check(): void
    {
        $order = $this->order();

        $this->inbound("order {$order->order_number}", ['from' => self::LID]);

        Queue::assertPushedOn('whatsapp-lookup', VerifyWhatsAppLidJob::class, fn ($job) => $job->orderId === $order->id && $job->lidChat === self::LID);
    }

    public function test_no_check_for_a_phone_sender_a_resolved_phone_a_test_order_or_an_already_tied_chat(): void
    {
        $order = $this->order();
        $this->inbound("order {$order->order_number}", ['from' => self::PHONE.'@c.us']);
        $this->inbound("order {$order->order_number}", ['from' => '999@lid', 'senderPhone' => self::PHONE]);
        $this->inbound("order {$this->order(['is_test' => true])->order_number}", ['from' => self::LID]);
        $this->inbound("order {$this->order(['customer_phone' => 'not a phone'])->order_number}", ['from' => self::LID]);

        WhatsappContact::query()->delete();
        WhatsappContact::query()->create(['phone' => self::PHONE, 'chat_id' => self::LID, 'opted_in_at' => now(), 'opt_in_source' => 'lid_match']);
        $this->inbound("order {$order->order_number}", ['from' => self::LID]);

        Queue::assertNotPushed(VerifyWhatsAppLidJob::class);
    }

    public function test_a_match_ties_the_chat_and_a_later_order_gets_its_receipt_without_asking(): void
    {
        $first = $this->order();
        $this->inbound("order {$first->order_number}", ['from' => self::LID]);
        $this->lookupAnswers(self::LID);

        $this->runJob($first);

        $contact = WhatsappContact::query()->sole();
        $this->assertSame([self::PHONE, self::LID, null], [$contact->phone, $contact->chat_id, $contact->opted_out_at]);
        Http::assertSent(fn ($request) => $request->url() === self::LOOKUP && $request->hasHeader('Authorization', 'Bearer cs-key'));

        $later = $this->order();
        $this->markDelivered($later);

        $receipt = CustomerNotification::query()->where('order_id', $later->id)->where('event', 'delivered_receipt')->sole();
        $this->assertSame(self::PHONE, $receipt->phone);
    }

    public function test_a_different_or_missing_lid_ties_nothing(): void
    {
        $order = $this->order();
        $this->inbound("order {$order->order_number}", ['from' => self::LID]);

        foreach (['111@lid', null] as $answer) {
            $this->lookupAnswers($answer);
            $this->runJob($order);

            $contact = WhatsappContact::query()->sole();
            $this->assertSame([null, self::LID], [$contact->phone, $contact->chat_id]);
        }
    }

    public function test_an_unanswered_lookup_throws_so_the_job_retries(): void
    {
        $order = $this->order();
        $this->lookupAnswers(null, 503);

        $this->expectException(RuntimeException::class);
        $this->runJob($order);
    }

    public function test_two_rows_merge_into_the_phone_row_and_stop_on_either_wins(): void
    {
        $order = $this->order();
        WhatsappContact::query()->create(['phone' => self::PHONE, 'opted_in_at' => now()->subDays(5), 'opt_in_source' => 'message']);
        WhatsappContact::query()->create(['chat_id' => self::LID, 'opted_in_at' => now(), 'opt_in_source' => 'message', 'opted_out_at' => now()]);
        $this->lookupAnswers(self::LID);

        $this->runJob($order);

        $contact = WhatsappContact::query()->sole();
        $this->assertSame([self::PHONE, self::LID], [$contact->phone, $contact->chat_id]);
        $this->assertNotNull($contact->opted_out_at);
        $this->assertTrue($contact->opted_in_at->lt(now()->subDays(4)));
        $this->assertFalse(WhatsappContact::receivesReceipts(self::PHONE, null));
    }

    public function test_a_phone_tied_to_an_older_lid_takes_the_new_one_and_keeps_its_stop(): void
    {
        $order = $this->order();
        WhatsappContact::query()->create(['phone' => self::PHONE, 'chat_id' => '555@lid', 'opted_in_at' => now(), 'opt_in_source' => 'lid_match', 'opted_out_at' => now()]);
        $this->lookupAnswers(self::LID);

        $this->runJob($order);

        $contact = WhatsappContact::query()->sole();
        $this->assertSame(self::LID, $contact->chat_id);
        $this->assertNotNull($contact->opted_out_at);
    }

    public function test_running_the_check_twice_changes_nothing_more(): void
    {
        $order = $this->order();
        $this->inbound("order {$order->order_number}", ['from' => self::LID]);
        $this->lookupAnswers(self::LID);

        $this->runJob($order);
        $this->runJob($order);

        $this->assertSame(1, WhatsappContact::query()->count());
    }

    public function test_the_admin_order_page_shows_the_verified_number_for_a_tied_chat(): void
    {
        $order = $this->order();
        app(CustomerNotificationService::class)->orderStatusCard($order, new WhatsAppRecipient(null, self::LID), 'm1');
        WhatsappContact::query()->create(['phone' => self::PHONE, 'chat_id' => self::LID, 'opted_in_at' => now(), 'opt_in_source' => 'lid_match']);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $row = $this->getJson("/api/orders/{$order->id}")->assertOk()->json('customer_notifications.0');

        $this->assertSame(self::LID, $row['chat_id']);
        $this->assertSame(self::PHONE, $row['verified_phone']);
    }

    /** A burst at an event must not hold up reseller bot replies, which share the one `default` worker. */
    public function test_the_check_runs_on_its_own_lane_behind_default(): void
    {
        $this->assertSame('whatsapp-lookup', (new VerifyWhatsAppLidJob(1, self::LID))->queue);
        $this->assertSame(['default', 'whatsapp-lookup'], config('horizon.defaults.supervisor-default.queue'));
    }

    public function test_repeat_messages_from_one_chat_queue_one_check(): void
    {
        $order = $this->order();

        $this->inbound("order {$order->order_number}", ['from' => self::LID]);
        $this->inbound("tolong check {$order->order_number}", ['from' => self::LID]);

        Queue::assertPushed(VerifyWhatsAppLidJob::class, 1);
    }

    public function test_lookups_are_rate_limited_so_a_burst_waits_instead_of_flooding_whatsapp(): void
    {
        $middleware = collect((new VerifyWhatsAppLidJob(1, self::LID))->middleware());

        $this->assertTrue($middleware->contains(fn ($m) => $m instanceof RateLimited));
        $limit = RateLimiter::limiter('whatsapp-lookup')(new VerifyWhatsAppLidJob(1, self::LID));
        $this->assertSame(20, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
    }
}
