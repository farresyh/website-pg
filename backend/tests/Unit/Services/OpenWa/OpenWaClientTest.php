<?php

namespace Tests\Unit\Services\OpenWa;

use App\Services\OpenWa\OpenWaClient;
use App\Services\OpenWa\OpenWaOutcomeUnknownException;
use App\Services\OpenWa\OpenWaPacingLimitedException;
use App\Services\OpenWa\OpenWaRecipientNotOnWhatsAppException;
use App\Services\OpenWa\OpenWaUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-116 2026-11-14 addendum, decision 4: `sendNow()` carries an
 * Idempotency-Key and turns each OpenWA answer into one outcome the outbox
 * job acts on.
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

    public function test_send_now_posts_with_the_idempotency_key(): void
    {
        Http::fake(['*' => Http::response(['messageId' => 'wa-1'], 201)]);

        $this->assertSame('wa-1', $this->client()->sendNow('chat-1', 'hello', 'cn-1-abc'));

        Http::assertSent(fn ($request) => $request->url() === 'https://openwa.test/api/sessions/session-1/messages/send-text'
            && $request['chatId'] === 'chat-1'
            && $request['text'] === 'hello'
            && $request->header('Idempotency-Key') === ['cn-1-abc']);
    }

    public function test_a_replayed_send_is_a_success(): void
    {
        Http::fake(['*' => Http::response(['messageId' => 'wa-1'], 201, ['Idempotent-Replayed' => 'true'])]);

        $this->assertSame('wa-1', $this->client()->sendNow('chat-1', 'hello', 'cn-1-abc'));
    }

    /** @return array<string, array{0: int, 1: array<string, mixed>, 2: class-string}> */
    public static function outcomes(): array
    {
        return [
            'pacing' => [429, ['code' => 'SEND_PACING_LIMITED', 'retryAfterSeconds' => 60], OpenWaPacingLimitedException::class],
            'key in progress' => [409, ['code' => 'IDEMPOTENCY_KEY_IN_PROGRESS'], OpenWaUnavailableException::class],
            'outcome unknown' => [409, ['code' => 'IDEMPOTENCY_OUTCOME_UNKNOWN'], OpenWaOutcomeUnknownException::class],
            'key reused' => [422, ['code' => 'IDEMPOTENCY_KEY_REUSED'], OpenWaOutcomeUnknownException::class],
            'engine not ready' => [409, ['message' => 'Session engine is not ready'], OpenWaUnavailableException::class],
            'session not started' => [400, ['message' => 'Session is not started'], OpenWaUnavailableException::class],
            'gateway error' => [503, ['error' => 'down'], OpenWaUnavailableException::class],
            'not on whatsapp' => [400, ['message' => 'WhatsApp reports recipient 6012@s.whatsapp.net is not registered'], OpenWaRecipientNotOnWhatsAppException::class],
            'our config is wrong' => [401, ['message' => 'Invalid API key'], RuntimeException::class],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  class-string  $expected
     */
    #[DataProvider('outcomes')]
    public function test_each_answer_maps_to_one_outcome(int $status, array $body, string $expected): void
    {
        Http::fake(['*' => Http::response($body, $status)]);

        try {
            $this->client()->sendNow('chat-1', 'hello', 'cn-1-abc');
            $this->fail('Expected the send to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame($expected, $e::class);
        }
    }

    public function test_an_unreachable_gateway_is_unavailable_not_failed(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->expectException(OpenWaUnavailableException::class);

        $this->client()->sendNow('chat-1', 'hello', 'cn-1-abc');
    }

    public function test_session_status_reports_a_restriction(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ready', 'restriction' => ['kind' => 'reachout_timelock', 'code' => 463]], 200)]);

        $this->assertSame(['status' => 'ready', 'restricted' => true], $this->client()->sessionStatus());
    }

    public function test_session_status_without_a_restriction(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ready', 'restriction' => null], 200)]);

        $this->assertSame(['status' => 'ready', 'restricted' => false], $this->client()->sessionStatus());
    }
}
