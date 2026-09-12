<?php

namespace Tests\Feature\Jobs\Reseller;

use App\Jobs\Reseller\SendResellerBotReplyJob;
use App\Services\OpenWa\OpenWaClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** E8 hardening — see `docs/build-log.md`'s 2026-09-10 reseller-family audit entry. */
class SendResellerBotReplyJobTest extends TestCase
{
    private function bindClient(): void
    {
        $this->app->bind(OpenWaClient::class, fn () => new OpenWaClient(
            baseUrl: 'https://openwa.test',
            sessionId: 'session-1',
            apiKey: 'key-1',
            timeoutSeconds: 10,
            connectTimeoutSeconds: 5,
        ));
    }

    public function test_is_pinned_to_the_orders_queue(): void
    {
        $this->assertSame('orders', (new SendResellerBotReplyJob('chat-1', 'hi'))->queue);
    }

    public function test_handle_sends_via_open_wa_client(): void
    {
        $this->bindClient();
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        app(SendResellerBotReplyJob::class, ['chatId' => 'chat-1', 'text' => 'hi'])->handle(app(OpenWaClient::class));

        Http::assertSent(fn ($request) => $request['chatId'] === 'chat-1' && $request['text'] === 'hi');
    }

    public function test_handle_throws_on_failure_so_the_queue_retries(): void
    {
        $this->bindClient();
        Http::fake(['*' => Http::response(['error' => 'down'], 503)]);

        $this->expectException(RuntimeException::class);

        app(SendResellerBotReplyJob::class, ['chatId' => 'chat-1', 'text' => 'hi'])->handle(app(OpenWaClient::class));
    }
}
