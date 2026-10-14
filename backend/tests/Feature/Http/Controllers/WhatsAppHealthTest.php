<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PRD §16 item 95: /api/health/whatsapp is a second UptimeRobot target.
 * It fails unless both OpenWA sessions are `ready`, which catches a
 * session left disconnected while the OpenWA process itself is up.
 */
class WhatsAppHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openwa.base_url' => 'http://openwa.test',
            'services.openwa.session_id' => 'bot-session',
            'services.openwa.api_key' => 'bot-key',
            'services.openwa.cs_session_id' => 'cs-session',
            'services.openwa.cs_api_key' => 'cs-key',
        ]);
    }

    private function fakeStatuses(string $bot, string $cs): void
    {
        Http::fake([
            'openwa.test/api/sessions/bot-session' => Http::response(['status' => $bot]),
            'openwa.test/api/sessions/cs-session' => Http::response(['status' => $cs]),
        ]);
    }

    public function test_ok_when_both_sessions_are_ready(): void
    {
        $this->fakeStatuses('ready', 'ready');

        $this->getJson('/api/health/whatsapp')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'sessions' => ['reseller-bot' => 'ready', 'customer-support' => 'ready']]);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer cs-key')
            && str_ends_with($request->url(), '/cs-session'));
    }

    public function test_degraded_when_one_session_is_disconnected(): void
    {
        $this->fakeStatuses('ready', 'disconnected');

        $this->getJson('/api/health/whatsapp')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('sessions.customer-support', 'disconnected');
    }

    public function test_degraded_when_openwa_is_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));

        $this->getJson('/api/health/whatsapp')
            ->assertStatus(503)
            ->assertJsonPath('sessions.reseller-bot', 'unreachable');
    }

    public function test_degraded_when_a_session_is_not_configured(): void
    {
        config(['services.openwa.cs_session_id' => null]);
        $this->fakeStatuses('ready', 'ready');

        $this->getJson('/api/health/whatsapp')
            ->assertStatus(503)
            ->assertJsonPath('sessions.customer-support', 'not_configured');
    }
}
