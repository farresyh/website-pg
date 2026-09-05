<?php

namespace App\Services\OpenWa;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ADR-075 / PR-F build addendum — the one seam that talks to the
 * self-hosted OpenWA gateway (github.com/rmyndharis/OpenWA). This
 * codebase only ever sends replies through it; every inbound event
 * (`message.received`, `session.status`) arrives via
 * `OpenWaWebhookController` instead, never polled.
 *
 * Deliberately not a `SupplierAdapter` — OpenWA is transport
 * infrastructure for the Reseller Bot channel, not a game-credit
 * supplier, and has none of that interface's order-lifecycle shape.
 */
final class OpenWaClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $sessionId,
        private readonly ?string $apiKey,
        private readonly int $timeoutSeconds,
        private readonly int $connectTimeoutSeconds,
    ) {}

    /**
     * Best-effort — a failed reply never rolls back the order/command
     * outcome it's reporting (the order/log write already committed by
     * the time this runs). Logged, not thrown, so one flaky reply can
     * never surface as a 500 on the webhook response OpenWA is waiting
     * on.
     */
    public function sendText(string $chatId, string $text): void
    {
        if ($this->sessionId === null || $this->apiKey === null) {
            Log::warning('OpenWaClient: sendText skipped — session_id/api_key not configured', ['chat_id' => $chatId]);

            return;
        }

        $response = Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->post("/api/sessions/{$this->sessionId}/messages/send-text", [
                'chatId' => $chatId,
                'text' => $text,
            ]);

        if ($response->failed()) {
            Log::error('OpenWaClient: send-text failed', [
                'chat_id' => $chatId,
                'status' => $response->status(),
            ]);
        }
    }
}
