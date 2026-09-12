<?php

namespace Tests\Feature\Http\Controllers\Webhooks;

use App\Services\OpenWa\OpenWaSessionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** ADR-075 decision 4 — the HMAC signature IS the auth, mirrors DigiflazzWebhookControllerTest's own shape. */
class OpenWaWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.openwa.webhook_secret' => 'test-secret',
            'services.openwa.webhook_signature_header' => 'X-OpenWA-Signature',
            'services.openwa.webhook_signature_algo' => 'sha256',
        ]);
    }

    /**
     * Real format confirmed against docs.open-wa.org, 2026-09-05:
     * `sha256=<hex>` — an `{algo}=` prefix before the digest, not a bare
     * hex string.
     */
    private function signedPost(array $payload): TestResponse
    {
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');

        return $this->call('POST', '/api/webhooks/openwa', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_OPENWA_SIGNATURE' => $signature,
        ], $body);
    }

    public function test_rejects_when_no_webhook_secret_is_configured(): void
    {
        config(['services.openwa.webhook_secret' => null]);

        $response = $this->postJson('/api/webhooks/openwa', ['event' => 'session.status']);

        $response->assertStatus(503);
    }

    public function test_rejects_an_invalid_signature(): void
    {
        $response = $this->call('POST', '/api/webhooks/openwa', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_OPENWA_SIGNATURE' => 'sha256=wrong',
        ], json_encode(['event' => 'session.status']));

        $response->assertStatus(401);
    }

    /**
     * Regression guard for the real bug found live: a signature missing
     * the `{algo}=` prefix (the shape this codebase originally, wrongly,
     * assumed) must still be rejected, not silently accepted as if the
     * prefix were optional.
     */
    public function test_rejects_a_signature_missing_the_algo_prefix(): void
    {
        $body = json_encode(['event' => 'session.status']);
        $bareHex = hash_hmac('sha256', $body, 'test-secret');

        $response = $this->call('POST', '/api/webhooks/openwa', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_OPENWA_SIGNATURE' => $bareHex,
        ], $body);

        $response->assertStatus(401);
    }

    public function test_accepts_a_validly_signed_request(): void
    {
        $response = $this->signedPost(['event' => 'ping']);

        $response->assertOk();
    }

    public function test_session_status_event_is_recorded(): void
    {
        $this->signedPost(['event' => 'session.status', 'data' => ['status' => 'connected']])->assertOk();

        $status = app(OpenWaSessionStatus::class)->current();
        $this->assertSame('connected', $status['status']);
    }

    public function test_a_non_group_message_received_event_is_ignored(): void
    {
        $response = $this->signedPost([
            'event' => 'message.received',
            'data' => ['isGroup' => false, 'chatId' => '60123@c.us', 'body' => '.baki', 'id' => 'msg1'],
        ]);

        $response->assertOk();
        // No group mapping exists and no reply mechanism is asserted here
        // (OpenWaClient::sendText is best-effort/logged, not mockable
        // without a container swap) — this test's real assertion is that
        // a DM never reaches ResellerBotService::handle() at all, proven
        // indirectly by no pending-link row being created for it.
        $this->assertDatabaseMissing('reseller_whatsapp_pending_links', ['whatsapp_group_id' => '60123@c.us']);
    }

    public function test_a_group_message_received_event_captures_a_pending_link(): void
    {
        $response = $this->signedPost([
            'event' => 'message.received',
            'data' => ['isGroup' => true, 'chatId' => 'g1@g.us', 'body' => 'hello', 'id' => 'msg1'],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('reseller_whatsapp_pending_links', ['whatsapp_group_id' => 'g1@g.us']);
    }

    /**
     * E6 hardening (2026-09-10 reseller-family audit): a newer OpenWA
     * server sends `kind` (server >= 0.23.4, docs.open-wa.org/changelog)
     * — preferred over `isGroup` when both are present.
     */
    public function test_kind_group_is_treated_as_a_group_message_even_when_is_group_is_false(): void
    {
        $response = $this->signedPost([
            'event' => 'message.received',
            'data' => ['kind' => 'group', 'isGroup' => false, 'chatId' => 'g1@g.us', 'body' => 'hello', 'id' => 'msg1'],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('reseller_whatsapp_pending_links', ['whatsapp_group_id' => 'g1@g.us']);
    }

    /** `kind: channel` must not be misrouted into the group-trust flow just because `isGroup` is (wrongly) true. */
    public function test_kind_channel_is_not_treated_as_a_group_message_even_when_is_group_is_true(): void
    {
        $response = $this->signedPost([
            'event' => 'message.received',
            'data' => ['kind' => 'channel', 'isGroup' => true, 'chatId' => 'c1@newsletter', 'body' => 'hello', 'id' => 'msg1'],
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('reseller_whatsapp_pending_links', ['whatsapp_group_id' => 'c1@newsletter']);
    }
}
