<?php

namespace Tests\Feature\Services\Notification;

use App\Jobs\SendCustomerWhatsAppJob;
use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateBranding;
use App\Models\AffiliateDomain;
use App\Models\CustomerNotification;
use App\Models\Order;
use App\Models\PlatformSettings;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-116 PR-B1: voucher codes reach the customer on WhatsApp.
 */
class CustomerWhatsAppNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openwa.base_url' => 'http://openwa.test',
            'services.openwa.cs_session_id' => 'cs-session',
            'services.openwa.cs_api_key' => 'cs-key',
            'services.openwa.notification_gap_min_seconds' => 10,
            'services.openwa.notification_gap_max_seconds' => 30,
        ]);
        PlatformSettings::current()->update(['whatsapp_notifications_enabled' => true]);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
    }

    private function failedOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'PG-TEST'.Str::upper(Str::random(5)),
            'customer_email' => 'buyer@example.com',
            'customer_name' => 'Aina Sofea',
            'customer_phone' => '012-345 6789',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 90,
            'final_amount' => 1090,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ], $overrides));
    }

    public function test_issuing_a_voucher_for_a_failed_order_queues_the_code_on_whatsapp(): void
    {
        Queue::fake();
        $order = $this->failedOrder();

        $this->postJson("/api/orders/{$order->id}/voucher")->assertCreated();

        $voucher = Voucher::query()->sole();
        $notification = CustomerNotification::query()->sole();
        $this->assertSame('voucher_issued', $notification->event);
        $this->assertSame('queued', $notification->status);
        $this->assertSame('60123456789', $notification->phone); // normalised, not the raw input
        $this->assertSame($order->id, $notification->order_id);
        $this->assertStringContainsString($voucher->code, $notification->message);
        $this->assertStringContainsString('RM10.00', $notification->message);
        $this->assertStringContainsString('Hi Aina', $notification->message);
        $this->assertStringContainsString('*PekanGame*', $notification->message);
        Queue::assertPushedOn('whatsapp', SendCustomerWhatsAppJob::class);
    }

    public function test_the_message_speaks_as_the_order_brand_and_links_its_own_domain(): void
    {
        Queue::fake();
        $brand = Affiliate::query()->create(['business_name' => 'FixFast', 'is_primary' => false, 'status' => 'active', 'markup_pct' => 0]);
        AffiliateBranding::query()->create(['affiliate_id' => $brand->id, 'store_name' => 'FixFast']);
        AffiliateDomain::query()->create(['affiliate_id' => $brand->id, 'hostname' => 'fixfastapp.com', 'status' => AffiliateDomainStatus::Active]);
        $order = $this->failedOrder(['affiliate_id' => $brand->id]);

        $this->postJson("/api/orders/{$order->id}/voucher")->assertCreated();

        $message = CustomerNotification::query()->sole()->message;
        $this->assertStringContainsString('*FixFast*', $message);
        $this->assertStringContainsString('fixfastapp.com', $message);
        $this->assertStringNotContainsString('PekanGame', $message);
    }

    public function test_with_the_switch_off_nothing_is_sent_but_the_admin_sees_why(): void
    {
        Queue::fake();
        PlatformSettings::current()->update(['whatsapp_notifications_enabled' => false]);
        $order = $this->failedOrder();

        $this->postJson("/api/orders/{$order->id}/voucher")->assertCreated();

        $notification = CustomerNotification::query()->sole();
        $this->assertSame('skipped', $notification->status);
        $this->assertSame('WhatsApp notifications are switched off', $notification->error);
        Queue::assertNothingPushed();
    }

    public function test_an_unusable_phone_is_skipped_not_sent(): void
    {
        Queue::fake();
        $order = $this->failedOrder(['customer_phone' => '12']);

        $this->postJson("/api/orders/{$order->id}/voucher")->assertCreated();

        $this->assertSame('skipped', CustomerNotification::query()->sole()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_test_order_never_messages_anyone(): void
    {
        Queue::fake();
        $order = $this->failedOrder(['is_test' => true]);

        $this->postJson("/api/orders/{$order->id}/voucher")->assertCreated();

        $this->assertSame(0, CustomerNotification::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_restore_only_order_tells_the_customer_their_voucher_balance_is_back(): void
    {
        Queue::fake();
        $original = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'PG-FULLCOVER',
            'customer_email' => 'buyer@example.com',
            'amount' => 1000,
            'remaining' => 0,
            'status' => 'exhausted',
            'reason' => 'earlier compensation',
        ]);
        $order = $this->failedOrder(['voucher_id' => $original->id, 'voucher_discount' => 1000, 'transaction_fee' => 0, 'final_amount' => 0]);
        VoucherRedemption::query()->create(['voucher_id' => $original->id, 'order_id' => $order->id, 'amount' => 1000, 'status' => 'reserved']);

        $this->postJson("/api/orders/{$order->id}/voucher")->assertCreated()->assertJsonPath('restored_only', true);
        // A repeat click is idempotent on the voucher side, and must be on the message side too.
        $this->postJson("/api/orders/{$order->id}/voucher")->assertCreated();

        $notification = CustomerNotification::query()->sole();
        $this->assertSame('voucher_restored', $notification->event);
        $this->assertStringContainsString('PG-FULLCOVER', $notification->message);
        $this->assertStringContainsString('RM10.00', $notification->message);
        Queue::assertPushed(SendCustomerWhatsAppJob::class, 1);
    }

    public function test_a_standalone_voucher_is_sent_only_when_the_admin_gave_a_phone(): void
    {
        Queue::fake();
        $base = ['customer_email' => 'fan@example.com', 'affiliate_id' => $this->primaryAffiliate()->id, 'amount' => 500, 'reason' => 'Promo'];

        $this->postJson('/api/vouchers', $base + ['idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame(0, CustomerNotification::query()->count());

        $this->postJson('/api/vouchers', $base + ['customer_phone' => '+60 11-2233 4455', 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $notification = CustomerNotification::query()->sole();
        $this->assertSame('601122334455', $notification->phone);
        $this->assertNull($notification->order_id);
        $this->assertStringContainsString('Hi there', $notification->message);
    }

    public function test_back_to_back_notifications_are_spaced_out(): void
    {
        Queue::fake();
        $first = $this->failedOrder();
        $second = $this->failedOrder();

        $this->postJson("/api/orders/{$first->id}/voucher")->assertCreated();
        $this->postJson("/api/orders/{$second->id}/voucher")->assertCreated();

        $delays = [];
        Queue::assertPushed(SendCustomerWhatsAppJob::class, function (SendCustomerWhatsAppJob $job) use (&$delays) {
            $delays[] = $job->delay;

            return true;
        });
        $gap = $delays[1]->getTimestamp() - $delays[0]->getTimestamp();
        $this->assertGreaterThanOrEqual(10, $gap);
        $this->assertLessThanOrEqual(30, $gap);
    }

    private function queuedNotification(): CustomerNotification
    {
        return CustomerNotification::query()->create([
            'event' => 'voucher_issued',
            'dedupe_key' => 'voucher_issued:voucher:1',
            'phone' => '60123456789',
            'message' => 'hello',
            'status' => 'queued',
        ]);
    }

    public function test_the_job_sends_from_the_customer_support_session_and_marks_it_sent(): void
    {
        Http::fake(['openwa.test/*' => Http::response(['success' => true], 200)]);
        $notification = $this->queuedNotification();

        (new SendCustomerWhatsAppJob($notification->id))->handle();

        Http::assertSent(fn ($request) => $request->url() === 'http://openwa.test/api/sessions/cs-session/messages/send-text'
            && $request['chatId'] === '60123456789@c.us'
            && $request->hasHeader('Authorization', 'Bearer cs-key'));
        $notification->refresh();
        $this->assertSame('sent', $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertNotNull($notification->sent_at);
    }

    public function test_an_openwa_pacing_refusal_waits_instead_of_failing(): void
    {
        Http::fake(['openwa.test/*' => Http::response(['code' => 'SEND_PACING_LIMITED', 'retryAfterSeconds' => 1200], 429)]);
        $notification = $this->queuedNotification();
        $job = (new SendCustomerWhatsAppJob($notification->id))->withFakeQueueInteractions();

        $job->handle();

        $job->assertReleased(1200);
        $notification->refresh();
        $this->assertSame('queued', $notification->status);
        $this->assertSame(0, $notification->attempts);
    }

    public function test_a_real_failure_throws_for_retry_and_failed_marks_the_row(): void
    {
        Http::fake(['openwa.test/*' => Http::response(['error' => 'boom'], 500)]);
        $notification = $this->queuedNotification();
        $job = new SendCustomerWhatsAppJob($notification->id);

        try {
            $job->handle();
            $this->fail('Expected the send to throw.');
        } catch (RuntimeException) {
        }
        $job->failed(new RuntimeException('send-text failed with status 500'));

        $notification->refresh();
        $this->assertSame('failed', $notification->status);
        $this->assertStringContainsString('500', $notification->error);
    }

    public function test_an_already_sent_row_is_never_sent_again(): void
    {
        Http::fake();
        $notification = $this->queuedNotification();
        $notification->update(['status' => 'sent']);

        (new SendCustomerWhatsAppJob($notification->id))->handle();

        Http::assertNothingSent();
    }
}
