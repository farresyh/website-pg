<?php

namespace Tests\Feature\Services\Membership;

use App\Services\Membership\PlunkMailer;
use App\Services\Membership\PlunkSendException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-027's 2026-08-29 addendum, decision 27/29: built against Plunk's
 * real API reference (docs.useplunk.com/api-reference/overview,
 * confirmed live 2026-08-29) — POST {base_url}/v1/send, Bearer auth,
 * `to`/`subject`/`body`/`from` fields (`from` required, sender domain
 * must be verified; send.fixfastapp.com provisioned 2026-08-29). The
 * test never hits the real API — it only proves the request
 * shape/error handling via Http::fake().
 */
class PlunkMailerTest extends TestCase
{
    private function mailer(): PlunkMailer
    {
        return new PlunkMailer(
            baseUrl: 'https://next-api.useplunk.com',
            apiKey: 'sk_test_fake_key',
            fromEmail: 'no-reply@send.fixfastapp.com',
            fromName: 'FixFastApp',
            timeoutSeconds: 5,
            connectTimeoutSeconds: 2,
        );
    }

    public function test_sends_the_otp_email_with_the_correct_request_shape(): void
    {
        Http::fake(['next-api.useplunk.com/v1/send' => Http::response(['success' => true], 200)]);

        $this->mailer()->sendOtpEmail('member@example.com', '123456');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://next-api.useplunk.com/v1/send'
                && $request->hasHeader('Authorization', 'Bearer sk_test_fake_key')
                && $request['from'] === ['name' => 'FixFastApp', 'email' => 'no-reply@send.fixfastapp.com']
                && $request['to'] === 'member@example.com'
                && str_contains($request['body'], '123456');
        });
    }

    public function test_throws_when_plunk_returns_a_non_success_response(): void
    {
        Http::fake(['next-api.useplunk.com/v1/send' => Http::response(['success' => false, 'error' => ['message' => 'Invalid API key']], 401)]);

        $this->expectException(PlunkSendException::class);

        $this->mailer()->sendOtpEmail('member@example.com', '123456');
    }

    public function test_throws_on_a_network_failure(): void
    {
        Http::fake(['next-api.useplunk.com/v1/send' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out')]);

        $this->expectException(PlunkSendException::class);

        $this->mailer()->sendOtpEmail('member@example.com', '123456');
    }
}
