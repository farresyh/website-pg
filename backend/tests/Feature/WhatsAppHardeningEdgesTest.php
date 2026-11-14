<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AffiliateUser;
use App\Models\CustomerNotification;
use App\Models\Reseller;
use App\Models\WhatsAppGroup;
use App\Services\Auth\AccountOwnerType;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Notification\WhatsAppOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ADR-075 / ADR-116 2026-11-14 addenda: the edges around the outbox — one
 * webhook handled once, WhatsApp's own failure report, the restricted
 * health check, the stuck-message alert, the portal banner's signal, and
 * the rule that nothing else talks to OpenWA's send route.
 */
class WhatsAppHardeningEdgesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openwa.base_url' => 'http://openwa.test',
            'services.openwa.webhook_secret' => 'test-secret',
            'services.openwa.webhook_signature_header' => 'X-OpenWA-Signature',
            'services.openwa.webhook_signature_algo' => 'sha256',
            'services.openwa.session_id' => 'bot-session',
            'services.openwa.api_key' => 'bot-key',
            'services.openwa.cs_session_id' => 'cs-session',
            'services.openwa.cs_api_key' => 'cs-key',
        ]);
        Queue::fake();
    }

    private function webhook(array $payload): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/webhooks/openwa', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_OPENWA_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'test-secret'),
        ], $body)->assertOk();
    }

    private function groupMessage(string $id): array
    {
        return [
            'event' => 'message.received',
            'sessionId' => 'bot-session',
            'data' => ['kind' => 'group', 'chatId' => 'g1@g.us', 'body' => '.baki', 'id' => $id, 'timestamp' => now()->getTimestamp(), 'fromMe' => false],
        ];
    }

    private function linkedReseller(): Reseller
    {
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);
        WhatsAppGroup::query()->create(['owner_type' => 'reseller', 'owner_id' => $reseller->id, 'whatsapp_group_id' => 'g1@g.us', 'is_active' => true]);

        return $reseller;
    }

    public function test_a_redelivered_webhook_is_handled_once(): void
    {
        $reseller = $this->linkedReseller();
        $payload = $this->groupMessage('m1');
        $payload['data']['body'] = '.xyz';

        $this->webhook($payload);
        $this->webhook($payload);

        // An unknown command is logged each time it is handled: once.
        $this->assertDatabaseCount('reseller_bot_command_logs', 1);
        $this->assertDatabaseHas('reseller_bot_command_logs', ['reseller_id' => $reseller->id, 'failure_reason' => 'unrecognized_command']);
    }

    /** The message's own time reaches the bot: a money command held by an outage is not run. */
    public function test_the_message_time_reaches_the_stale_guard(): void
    {
        $this->linkedReseller();
        $payload = $this->groupMessage('m2');
        $payload['data']['body'] = '.batalbaki';
        $payload['data']['timestamp'] = now()->subMinutes(10)->getTimestamp();

        $this->webhook($payload);

        $this->assertStringContainsString('tidak diproses', CustomerNotification::query()->sole()->message);
    }

    public function test_an_image_with_no_text_is_ignored_quietly(): void
    {
        $payload = $this->groupMessage('m3');
        unset($payload['data']['body']);

        $this->webhook($payload);

        // An unlinked group would have been captured for linking had the message been read.
        $this->assertDatabaseCount('whatsapp_pending_links', 0);
    }

    public function test_message_failed_marks_the_sent_customer_message_failed(): void
    {
        $row = CustomerNotification::query()->create([
            'session' => CustomerNotification::SESSION_CUSTOMER_SUPPORT, 'event' => 'delivered_receipt', 'dedupe_key' => 'r:1',
            'phone' => '60123456789', 'message' => 'hi', 'status' => CustomerNotification::STATUS_SENT, 'wa_message_id' => 'wa-9',
        ]);

        $this->webhook(['event' => 'message.failed', 'sessionId' => 'cs-session', 'data' => ['messageId' => 'wa-9', 'status' => 'failed', 'ack' => -1]]);

        $this->assertSame(CustomerNotification::STATUS_FAILED, $row->fresh()->status);
    }

    public function test_health_is_degraded_while_a_session_is_restricted(): void
    {
        Http::fake([
            'openwa.test/api/sessions/bot-session' => Http::response(['status' => 'ready', 'restriction' => null]),
            'openwa.test/api/sessions/cs-session' => Http::response(['status' => 'ready', 'restriction' => ['kind' => 'reachout_timelock', 'code' => 463]]),
        ]);

        $this->getJson('/api/health/whatsapp')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'degraded', 'sessions' => ['reseller-bot' => 'ready', 'customer-support' => 'restricted']]);
    }

    public function test_a_stuck_message_emails_the_admins_once_per_half_hour(): void
    {
        config(['services.plunk.api_key' => 'plunk-key', 'services.plunk.base_url' => 'http://plunk.test']);
        AdminUser::factory()->create(['email' => 'boss@example.test', 'is_active' => true]);
        app(WhatsAppOutbox::class)->reply(WhatsAppOutbox::EVENT_BOT_ORDER_UPDATE, 'bot_order:1:delivered:0', 'g1@g.us', 'berjaya');
        Http::fake(['plunk.test/*' => Http::response(['success' => true])]);

        $this->travel(6)->minutes();
        $this->artisan('app:alert-whatsapp-outbox')->assertSuccessful();
        $this->artisan('app:alert-whatsapp-outbox')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['to'] === 'boss@example.test' && str_contains($request['subject'], 'WhatsApp'));
    }

    public function test_the_portal_banner_signal_is_a_boolean_for_the_bot_only(): void
    {
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);
        Sanctum::actingAs(AffiliateUser::query()->create([
            'owner_type' => AccountOwnerType::Reseller->value, 'owner_id' => $reseller->id, 'name' => 'Owner',
            'email' => 'o@example.test', 'password' => bcrypt('password'), 'is_active' => true,
        ]), ['*'], 'affiliate');
        Http::fake(['openwa.test/api/sessions/bot-session' => Http::response(['status' => 'disconnected'])]);

        $this->getJson('/api/reseller-portal/bot-status')->assertOk()->assertExactJson(['degraded' => true]);
    }

    /** Decision 1: the outbox job is the only sender, so no second send path can grow back. */
    public function test_only_the_outbox_job_calls_send_now(): void
    {
        $callers = [];
        foreach ((new Finder)->files()->in(app_path())->name('*.php')->contains('->sendNow(') as $file) {
            $callers[] = str_replace(app_path().'/', '', $file->getRealPath());
        }
        sort($callers);

        // The retired reply job only drains for one release (ADR-116 2026-11-14 addendum).
        $this->assertSame(['Jobs/Reseller/SendResellerBotReplyJob.php', 'Jobs/SendCustomerWhatsAppJob.php'], $callers);
    }
}
