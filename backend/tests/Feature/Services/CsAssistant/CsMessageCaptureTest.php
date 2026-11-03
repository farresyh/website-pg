<?php

namespace Tests\Feature\Services\CsAssistant;

use App\Jobs\ClassifyCsOutgoingMessageJob;
use App\Jobs\SendCustomerWhatsAppJob;
use App\Models\CsMessage;
use App\Models\CustomerNotification;
use App\Services\CsAssistant\CsMessageRecorder;
use App\Services\CsAssistant\CsSenderClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ADR-136 PR 1 (decisions 8, 19, 32, 33): every personal-chat message on the
 * customer-support number is kept, masked, with who sent it (customer, staff,
 * or our own system send). No AI in this PR.
 */
class CsMessageCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '60123456789';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openwa.webhook_secret' => 'test-secret',
            'services.openwa.session_id' => 'bot-session',
            'services.openwa.cs_session_id' => 'cs-session',
            'services.openwa.cs_api_key' => 'cs-key',
            'services.openwa.base_url' => 'http://openwa.test',
        ]);
        Queue::fake();
    }

    /** @param  array<string, mixed>  $data */
    private function event(string $event, array $data, string $session = 'cs-session'): TestResponse
    {
        $payload = json_encode(['event' => $event, 'sessionId' => $session, 'data' => $data]);

        return $this->call('POST', '/api/webhooks/openwa', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_OPENWA_SIGNATURE' => 'sha256='.hash_hmac('sha256', $payload, 'test-secret'),
        ], $payload)->assertOk();
    }

    /** @return array<string, mixed> */
    private function message(string $body, array $overrides = []): array
    {
        return $overrides + [
            'kind' => 'individual',
            'from' => self::PHONE.'@c.us',
            'chatId' => self::PHONE.'@c.us',
            'body' => $body,
            'id' => strtoupper(Str::random(20)),
            'fromMe' => false,
            'type' => 'text',
            'timestamp' => now()->timestamp,
        ];
    }

    private function runClassifyJobs(): void
    {
        Queue::assertPushed(ClassifyCsOutgoingMessageJob::class);
        foreach (Queue::pushed(ClassifyCsOutgoingMessageJob::class) as $job) {
            app()->call([$job, 'handle']);
        }
    }

    public function test_a_customer_message_is_kept_masked_with_its_order_number_apart(): void
    {
        $this->event('message.received', $this->message('bang order PG-ABCDEF123456 tak masuk, ID 51049607, call 0198765432 or a@b.com'));

        $row = CsMessage::query()->sole();
        $this->assertSame('in', $row->direction);
        $this->assertSame(CsMessage::SENDER_CUSTOMER, $row->sender);
        $this->assertSame(['PG-ABCDEF123456'], $row->order_numbers);
        $this->assertStringNotContainsString('51049607', $row->body);
        $this->assertStringNotContainsString('0198765432', $row->body);
        $this->assertStringNotContainsString('a@b.com', $row->body);
        $this->assertStringNotContainsString('PG-ABCDEF123456', $row->body);
        $this->assertStringContainsString('tak masuk', $row->body);
        // The chat is kept as a keyed hash, never the phone.
        $this->assertStringNotContainsString(self::PHONE, $row->chat_hash);
        $this->assertSame(CsMessageRecorder::chatHash(self::PHONE.'@c.us'), $row->chat_hash);
    }

    public function test_group_messages_and_other_sessions_are_not_kept(): void
    {
        $this->event('message.received', $this->message('hi', ['kind' => 'group', 'chatId' => '1203@g.us', 'from' => '1203@g.us']));
        $this->event('message.received', $this->message('hi'), 'bot-session');

        $this->assertSame(0, CsMessage::query()->count());
    }

    public function test_channels_status_updates_and_broadcast_lists_are_not_kept_either_way(): void
    {
        $this->event('message.received', $this->message('channel post', ['kind' => 'channel', 'chatId' => '1203630@newsletter', 'from' => '1203630@newsletter']));
        $this->event('message.received', $this->message('status', ['kind' => 'status', 'chatId' => 'status@broadcast', 'from' => 'status@broadcast']));
        $this->event('message.received', $this->message('list', ['kind' => 'broadcast', 'chatId' => '1700000000@broadcast', 'from' => '1700000000@broadcast']));
        // An older payload with no `kind` is judged by the chat id alone.
        $this->event('message.received', array_diff_key($this->message('channel post', ['chatId' => '1203630@newsletter', 'from' => '1203630@newsletter']), ['kind' => true]));
        // A post or reply we make in a channel is not a support message either.
        $this->event('message.sent', $this->message('our channel post', ['kind' => 'channel', 'chatId' => '1203630@newsletter', 'fromMe' => true]));

        $this->assertSame(0, CsMessage::query()->count());
        Queue::assertNotPushed(ClassifyCsOutgoingMessageJob::class);
    }

    public function test_a_redelivered_event_is_kept_once(): void
    {
        $message = $this->message('hello');
        $this->event('message.received', $message);
        $this->event('message.received', $message);

        $this->assertSame(1, CsMessage::query()->count());
    }

    public function test_an_image_without_text_is_kept_as_its_type(): void
    {
        $this->event('message.received', $this->message('', ['type' => 'image']));

        $row = CsMessage::query()->sole();
        $this->assertSame('image', $row->type);
        $this->assertNull($row->body);
    }

    public function test_our_own_send_is_classified_as_system_by_its_message_id(): void
    {
        $notification = $this->notification(['wa_message_id' => '3EB0AAA111', 'message' => 'Your order is complete']);

        $this->event('message.sent', $this->message('Your order is complete', ['id' => '3EB0AAA111', 'fromMe' => true, 'from' => 'me']));
        $this->runClassifyJobs();

        $row = CsMessage::query()->sole();
        $this->assertSame('out', $row->direction);
        $this->assertSame(CsMessage::SENDER_SYSTEM, $row->sender);
        $this->assertSame($notification->id, $row->customer_notification_id);
        $this->assertNull($row->body);
    }

    public function test_an_id_that_differs_only_by_its_engine_prefix_still_matches(): void
    {
        $this->notification(['wa_message_id' => 'true_60123456789@c.us_3EB0BBB222']);

        $this->event('message.sent', $this->message('x', ['id' => '3EB0BBB222', 'fromMe' => true]));
        $this->runClassifyJobs();

        $this->assertSame(CsMessage::SENDER_SYSTEM, CsMessage::query()->sole()->sender);
    }

    public function test_our_send_without_a_stored_id_matches_by_chat_and_exact_text(): void
    {
        $this->notification(['message' => 'Done. You won\'t receive order receipts here anymore.', 'sent_at' => now()->subSeconds(20)]);

        $this->event('message.sent', $this->message('Done. You won\'t receive order receipts here anymore.', ['fromMe' => true]));
        $this->runClassifyJobs();

        $this->assertSame(CsMessage::SENDER_SYSTEM, CsMessage::query()->sole()->sender);
    }

    public function test_anything_else_sent_from_the_number_is_staff_and_kept_masked(): void
    {
        $this->notification(['message' => 'Your order is complete', 'sent_at' => now()->subSeconds(20)]);

        $this->event('message.sent', $this->message('hii boss, 250+250 tu first topup je. call 0198765432', ['fromMe' => true]));
        $this->runClassifyJobs();

        $row = CsMessage::query()->sole();
        $this->assertSame(CsMessage::SENDER_STAFF, $row->sender);
        $this->assertStringContainsString('first topup', $row->body);
        $this->assertStringNotContainsString('0198765432', $row->body);
    }

    public function test_a_send_still_in_flight_to_that_chat_means_wait(): void
    {
        $this->notification(['status' => CustomerNotification::STATUS_QUEUED, 'attempts' => 1, 'sent_at' => null]);

        $verdict = app(CsSenderClassifier::class)->classify(self::PHONE.'@c.us', '9F00CCC333', 'some text', now());

        $this->assertSame(CsSenderClassifier::WAIT, $verdict['sender']);
    }

    public function test_the_send_job_stores_openwas_message_id(): void
    {
        Http::fake(['openwa.test/*' => Http::response(['messageId' => '3EB0DDD444', 'timestamp' => now()->timestamp], 201)]);
        $notification = $this->notification(['status' => CustomerNotification::STATUS_QUEUED, 'sent_at' => null]);

        (new SendCustomerWhatsAppJob($notification->id))->handle();

        $this->assertSame('3EB0DDD444', $notification->fresh()->wa_message_id);
        $this->assertSame(CustomerNotification::STATUS_SENT, $notification->fresh()->status);
    }

    public function test_history_import_keeps_personal_chats_once_and_classifies_outgoing(): void
    {
        $sentAt = now()->subDays(20)->startOfMinute();
        $this->notification(['message' => 'Status card text', 'sent_at' => $sentAt]);

        $rows = [
            ['waMessageId' => 'IN1', 'chatId' => self::PHONE.'@c.us', 'from' => self::PHONE.'@c.us', 'body' => 'tak dapat 500 diamond', 'type' => 'text', 'direction' => 'incoming', 'timestamp' => $sentAt->timestamp - 30],
            ['waMessageId' => 'OUT1', 'chatId' => self::PHONE.'@c.us', 'from' => 'me', 'body' => 'Status card text', 'type' => 'text', 'direction' => 'outgoing', 'timestamp' => $sentAt->timestamp + 5],
            ['waMessageId' => 'OUT2', 'chatId' => self::PHONE.'@c.us', 'from' => 'me', 'body' => 'hii, item tu first topup je', 'type' => 'text', 'direction' => 'outgoing', 'timestamp' => $sentAt->timestamp + 60],
            ['waMessageId' => 'GRP1', 'chatId' => '1203@g.us', 'from' => '1203@g.us', 'body' => 'group chatter', 'type' => 'text', 'direction' => 'incoming', 'timestamp' => $sentAt->timestamp],
        ];
        Http::fake(['openwa.test/*' => Http::sequence()
            ->push(['messages' => $rows, 'total' => 4])
            ->push(['messages' => [], 'total' => 4])
            ->push(['messages' => $rows, 'total' => 4])
            ->push(['messages' => [], 'total' => 4])]);

        $this->artisan('cs:import-history', ['--since' => '2026-09-25'])->assertSuccessful();
        $this->artisan('cs:import-history', ['--since' => '2026-09-25'])->assertSuccessful();

        $this->assertSame(3, CsMessage::query()->count());
        $this->assertSame(CsMessage::SENDER_CUSTOMER, CsMessage::query()->where('wa_message_id', 'IN1')->value('sender'));
        $this->assertSame(CsMessage::SENDER_SYSTEM, CsMessage::query()->where('wa_message_id', 'OUT1')->value('sender'));
        $this->assertSame(CsMessage::SENDER_STAFF, CsMessage::query()->where('wa_message_id', 'OUT2')->value('sender'));
        $this->assertSame(CsMessage::SOURCE_IMPORT, CsMessage::query()->where('wa_message_id', 'IN1')->value('source'));
    }

    /** @param  array<string, mixed>  $overrides */
    private function notification(array $overrides = []): CustomerNotification
    {
        return CustomerNotification::query()->create($overrides + [
            'session' => CustomerNotification::SESSION_CUSTOMER_SUPPORT,
            'event' => 'status_card',
            'dedupe_key' => 'k-'.Str::random(8),
            'phone' => self::PHONE,
            'message' => 'Status card text',
            'status' => CustomerNotification::STATUS_SENT,
            'attempts' => 1,
            'sent_at' => now()->subSeconds(5),
        ]);
    }
}
