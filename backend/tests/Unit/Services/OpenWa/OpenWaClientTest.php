<?php

namespace Tests\Unit\Services\OpenWa;

use App\Jobs\Reseller\SendResellerBotReplyJob;
use App\Services\OpenWa\OpenWaClient;
use App\Services\OpenWa\OpenWaRecipientNotOnWhatsAppException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * E8 hardening (2026-09-10 reseller-family audit, `docs/build-log.md`):
 * `sendText()` now dispatches `SendResellerBotReplyJob` instead of making
 * the HTTP call inline — this pins that dispatch contract directly,
 * rather than only through other tests' incidental sync-queue coverage.
 */
class OpenWaClientTest extends TestCase
{
    private function client(?string $sessionId = 'session-1', ?string $apiKey = 'key-1'): OpenWaClient
    {
        return new OpenWaClient(
            baseUrl: 'https://openwa.test',
            sessionId: $sessionId,
            apiKey: $apiKey,
            timeoutSeconds: 10,
            connectTimeoutSeconds: 5,
        );
    }

    public function test_send_text_dispatches_the_reply_job_when_configured(): void
    {
        Queue::fake();

        $this->client()->sendText('chat-1', 'hello');

        Queue::assertPushed(SendResellerBotReplyJob::class, fn (SendResellerBotReplyJob $job) => $job->chatId === 'chat-1' && $job->text === 'hello');
    }

    public function test_send_text_dispatches_nothing_when_unconfigured(): void
    {
        Queue::fake();

        $this->client(sessionId: null, apiKey: null)->sendText('chat-1', 'hello');

        Queue::assertNotPushed(SendResellerBotReplyJob::class);
    }

    public function test_send_now_posts_to_the_expected_endpoint(): void
    {
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $this->client()->sendNow('chat-1', 'hello');

        Http::assertSent(fn ($request) => $request->url() === 'https://openwa.test/api/sessions/session-1/messages/send-text'
            && $request['chatId'] === 'chat-1'
            && $request['text'] === 'hello');
    }

    public function test_send_now_throws_on_a_failed_response_so_the_job_retries(): void
    {
        Http::fake(['*' => Http::response(['error' => 'down'], 503)]);

        $this->expectException(RuntimeException::class);

        $this->client()->sendNow('chat-1', 'hello');
    }

    /** PRD §16 item 96: OpenWA v0.25.0's Baileys answer for a number that is not on WhatsApp. */
    public function test_a_number_not_on_whatsapp_throws_the_non_retryable_exception(): void
    {
        Http::fake(['*' => Http::response([
            'message' => 'WhatsApp reports recipient 60123456789@s.whatsapp.net is not registered',
            'error' => 'Bad Request',
            'statusCode' => 400,
        ], 400)]);

        $this->expectException(OpenWaRecipientNotOnWhatsAppException::class);

        $this->client()->sendNow('60123456789@c.us', 'hello');
    }

    /** OpenWA also answers 400 while a session is still starting (just after a restart); that must retry. */
    public function test_a_400_for_an_unstarted_session_stays_retryable(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Session is not started', 'error' => 'Bad Request', 'statusCode' => 400], 400)]);

        try {
            $this->client()->sendNow('chat-1', 'hello');
            $this->fail('Expected the send to throw.');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(OpenWaRecipientNotOnWhatsAppException::class, $e);
        }
    }
}
