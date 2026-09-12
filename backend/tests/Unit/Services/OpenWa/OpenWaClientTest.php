<?php

namespace Tests\Unit\Services\OpenWa;

use App\Jobs\Reseller\SendResellerBotReplyJob;
use App\Services\OpenWa\OpenWaClient;
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
}
