<?php

namespace Tests\Feature\Jobs\Reseller;

use App\Jobs\Reseller\SendResellerBotReplyJob;
use App\Services\OpenWa\OpenWaClient;
use App\Services\OpenWa\OpenWaRecipientNotOnWhatsAppException;
use Illuminate\Pipeline\Pipeline;
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

    /** ADR-048 addendum (2026-09-29): moved off the order lanes. */
    public function test_is_pinned_to_the_default_queue(): void
    {
        $this->assertSame('default', (new SendResellerBotReplyJob('chat-1', 'hi'))->queue);
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

    /** PRD §16 item 96: a number that is not on WhatsApp fails at once instead of retrying 3 times. */
    public function test_a_number_not_on_whatsapp_fails_the_job_without_retrying(): void
    {
        $this->bindClient();
        Http::fake(['*' => Http::response(['message' => 'WhatsApp reports recipient 6012@s.whatsapp.net is not registered'], 400)]);
        $job = (new SendResellerBotReplyJob('6012@c.us', 'hi'))->withFakeQueueInteractions();

        $this->expectException(OpenWaRecipientNotOnWhatsAppException::class);

        try {
            (new Pipeline($this->app))->send($job)->through($job->middleware())->then(fn ($job) => $job->handle(app(OpenWaClient::class)));
        } finally {
            $job->assertFailed();
        }
    }

    public function test_any_other_failure_is_left_to_the_queue_to_retry(): void
    {
        $this->bindClient();
        Http::fake(['*' => Http::response(['message' => 'Session is not started'], 400)]);
        $job = (new SendResellerBotReplyJob('chat-1', 'hi'))->withFakeQueueInteractions();

        try {
            (new Pipeline($this->app))->send($job)->through($job->middleware())->then(fn ($job) => $job->handle(app(OpenWaClient::class)));
        } catch (RuntimeException) {
        }

        $job->assertNotFailed();
    }
}
