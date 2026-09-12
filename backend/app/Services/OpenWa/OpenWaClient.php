<?php

namespace App\Services\OpenWa;

use App\Jobs\Reseller\SendResellerBotReplyJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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
     * the time this runs). Never throws, so one flaky reply can never
     * surface as a 500 on the webhook response OpenWA is waiting on.
     *
     * E8 hardening (2026-09-10 reseller-family audit, `docs/build-log.md`):
     * the actual HTTP call is queued (`SendResellerBotReplyJob`, 3
     * tries) rather than made inline here — a call site keeps this same
     * fire-and-forget contract, but a transient OpenWA outage now
     * retries instead of silently losing the reply.
     */
    public function sendText(string $chatId, string $text): void
    {
        if ($this->sessionId === null || $this->apiKey === null) {
            Log::warning('OpenWaClient: sendText skipped — session_id/api_key not configured', ['chat_id' => $chatId]);

            return;
        }

        SendResellerBotReplyJob::dispatch($chatId, $text);
    }

    /**
     * The actual HTTP call — only `SendResellerBotReplyJob` calls this.
     * Throws on failure (unlike `sendText()`) so the job's queue retry
     * kicks in; `session_id`/`api_key` are assumed already validated by
     * `sendText()` at dispatch time.
     */
    public function sendNow(string $chatId, string $text): void
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->apiKey)
            ->acceptJson()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->post("/api/sessions/{$this->sessionId}/messages/send-text", [
                'chatId' => $chatId,
                'text' => $text,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("OpenWaClient: send-text failed with status {$response->status()}");
        }
    }
}
