<?php

namespace Tests\Feature\Services\Notification;

use App\Jobs\SendCustomerWhatsAppJob;
use App\Models\CustomerNotification;
use App\Models\Order;
use App\Services\Notification\WhatsAppOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ADR-116 2026-11-14 addendum: one outbox for every WhatsApp send, sent
 * idempotently, retried until the gateway is back, on the right lane, and
 * never sent once it is stale or overtaken.
 */
class WhatsAppOutboxHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = '120363000000000001@g.us';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openwa.base_url' => 'http://openwa.test',
            'services.openwa.session_id' => 'bot-session',
            'services.openwa.api_key' => 'bot-key',
        ]);
        Queue::fake();
    }

    private function reply(string $event, string $key, string $text = 'hello', ?int $orderId = null): CustomerNotification
    {
        app(WhatsAppOutbox::class)->reply($event, $key, self::GROUP, $text, $orderId);

        return CustomerNotification::query()->where('dedupe_key', $key)->firstOrFail();
    }

    private function sendRow(CustomerNotification $row): SendCustomerWhatsAppJob
    {
        $job = (new SendCustomerWhatsAppJob($row->id))->withFakeQueueInteractions();
        $job->handle();

        return $job;
    }

    public function test_a_reply_goes_on_the_reply_lane_at_once_after_commit(): void
    {
        $row = $this->reply(WhatsAppOutbox::EVENT_BOT_REPLY, 'reply:a:0');

        Queue::assertPushedOn(WhatsAppOutbox::LANE_REPLY, SendCustomerWhatsAppJob::class, fn (SendCustomerWhatsAppJob $job) => $job->afterCommit === true);
        $this->assertTrue($row->due_at->lessThanOrEqualTo(now()));
    }

    public function test_a_customer_message_stays_on_the_paced_lane(): void
    {
        $this->assertSame(WhatsAppOutbox::LANE_PACED, WhatsAppOutbox::laneFor('delivered_receipt'));
        $this->assertSame(WhatsAppOutbox::LANE_PACED, WhatsAppOutbox::laneFor('supplier_order_post'));
    }

    public function test_every_attempt_of_a_row_sends_the_same_idempotency_key(): void
    {
        $row = $this->reply(WhatsAppOutbox::EVENT_BOT_MONEY_REPLY, 'reply:b:0');
        Http::fakeSequence()
            ->push(['code' => 'IDEMPOTENCY_KEY_IN_PROGRESS'], 409)
            ->push(['messageId' => 'wa-1'], 201, ['Idempotent-Replayed' => 'true']);

        $this->sendRow($row)->assertReleased();
        $this->sendRow($row->fresh());

        $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])->unique();
        $this->assertCount(1, $keys);
        $this->assertSame(CustomerNotification::STATUS_SENT, $row->fresh()->status);
        $this->assertSame('wa-1', $row->fresh()->wa_message_id);
    }

    /** Decision 5: the gateway being down is a wait, not a failure. */
    public function test_an_unreachable_gateway_is_retried_not_failed(): void
    {
        $row = $this->reply(WhatsAppOutbox::EVENT_BOT_ORDER_UPDATE, 'bot_order:1:delivered:0');
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->sendRow($row)->assertReleased();

        $this->assertSame(CustomerNotification::STATUS_QUEUED, $row->fresh()->status);
    }

    /** Decision 4: a message that may already be in the chat is never sent again. */
    public function test_an_unknown_outcome_is_never_resent(): void
    {
        $row = $this->reply(WhatsAppOutbox::EVENT_BOT_MONEY_REPLY, 'reply:c:0');
        Http::fake(['*' => Http::response(['code' => 'IDEMPOTENCY_OUTCOME_UNKNOWN'], 409)]);

        $job = $this->sendRow($row);

        $job->assertNotReleased();
        $this->assertSame(CustomerNotification::STATUS_UNKNOWN, $row->fresh()->status);
    }

    /** Decision 7: a read reply that missed its 3 minutes is dropped, not sent late. */
    public function test_a_stale_read_reply_expires(): void
    {
        $row = $this->reply(WhatsAppOutbox::EVENT_BOT_REPLY, 'reply:d:0');
        $this->travel(4)->minutes();
        Http::fake();

        $this->sendRow($row);

        Http::assertNothingSent();
        $this->assertSame(CustomerNotification::STATUS_EXPIRED, $row->fresh()->status);
    }

    public function test_a_money_message_survives_the_same_delay_and_says_it_is_late(): void
    {
        $row = $this->reply(WhatsAppOutbox::EVENT_BOT_ORDER_UPDATE, 'bot_order:2:delivered:0', '✅ Berjaya');
        $this->travel(30)->minutes();
        Http::fake(['*' => Http::response(['messageId' => 'wa-2'], 201)]);

        $this->sendRow($row);

        Http::assertSent(fn ($request) => str_starts_with($request['text'], '✅ Berjaya') && str_contains($request['text'], 'Dikemas kini lewat'));
        $this->assertSame(CustomerNotification::STATUS_SENT, $row->fresh()->status);
    }

    /** Decision 8: a "received" reply overtaken by the order's final status is not sent. */
    public function test_an_overtaken_received_reply_is_superseded(): void
    {
        $received = $this->reply(WhatsAppOutbox::EVENT_BOT_MONEY_REPLY, 'reply:e:0', 'diterima', orderId: null);
        $received->forceFill(['order_id' => $this->order()])->save();
        $this->reply(WhatsAppOutbox::EVENT_BOT_ORDER_UPDATE, "bot_order:{$received->order_id}:delivered:0", 'berjaya', $received->order_id);
        Http::fake();

        $this->sendRow($received);

        Http::assertNothingSent();
        $this->assertSame(CustomerNotification::STATUS_SUPERSEDED, $received->fresh()->status);
    }

    public function test_an_unconfigured_session_skips_instead_of_failing(): void
    {
        config(['services.openwa.session_id' => null]);
        $row = $this->reply(WhatsAppOutbox::EVENT_BOT_REPLY, 'reply:g:0');
        Http::fake();

        $this->sendRow($row);

        Http::assertNothingSent();
        $this->assertSame(CustomerNotification::STATUS_SKIPPED, $row->fresh()->status);
    }

    private function order(): int
    {
        return Order::query()->create([
            'placed_via' => 'reseller_bot',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'PG-OUTBOX00001',
            'customer_email' => 'r@example.com',
            'player_id' => '1',
            'cost_price' => 900,
            'standard_selling_price' => 1000,
            'selling_price' => 1000,
            'transaction_fee' => 0,
            'final_amount' => 1000,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => 'paid',
            'delivery_status' => 'delivered',
        ])->id;
    }
}
