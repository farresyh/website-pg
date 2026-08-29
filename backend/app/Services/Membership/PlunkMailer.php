<?php

namespace App\Services\Membership;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * ADR-027's 2026-08-29 addendum, decisions 27/29: sends the OTP code
 * via Plunk's transactional email API — built against Plunk's own real
 * API reference (docs.useplunk.com/api-reference/overview, confirmed
 * live 2026-08-29: `POST {base_url}/v1/send`, `Authorization: Bearer
 * <secret key>`, JSON body `to`/`subject`/`body`), not an assumed
 * shape. Same short-timeout discipline as XenditGateway/GamevionAdapter
 * — this can be called on a customer-facing verify request, never left
 * to hang indefinitely.
 */
final class PlunkMailer
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 10,
        private readonly int $connectTimeoutSeconds = 5,
    ) {
    }

    public function sendOtpEmail(string $to, string $code): void
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withToken($this->apiKey)
                ->timeout($this->timeoutSeconds)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->post('/v1/send', [
                    'to' => $to,
                    'subject' => 'Your verification code',
                    'body' => "Your verification code is: {$code}. It expires in 10 minutes. If you didn't request this, ignore this email.",
                ]);
        } catch (ConnectionException $e) {
            throw new PlunkSendException("Plunk send failed: {$e->getMessage()}", previous: $e);
        }

        if (! $response->successful()) {
            throw new PlunkSendException("Plunk send failed: HTTP {$response->status()} {$response->body()}");
        }
    }
}
