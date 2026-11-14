<?php

namespace App\Services\OpenWa;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * ADR-075 / PR-F build addendum — the one seam that talks to the
 * self-hosted OpenWA gateway (github.com/rmyndharis/OpenWA). Every inbound
 * event arrives via `OpenWaWebhookController`, never polled. Since the
 * ADR-116 2026-11-14 addendum the only sender is the outbox job
 * (`SendCustomerWhatsAppJob`, guarded by an architecture test).
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

    /** Whether this session has its id and key (unset in tests and local dev). */
    public function isConfigured(): bool
    {
        return $this->sessionId !== null && $this->apiKey !== null;
    }

    /**
     * ADR-116 decision 11: the same client pointed at the `customer-support`
     * session. The container binding above stays the reseller bot's.
     */
    public static function customerSupport(): self
    {
        $config = config('services.openwa');

        return new self(
            baseUrl: $config['base_url'],
            sessionId: $config['cs_session_id'],
            apiKey: $config['cs_api_key'],
            timeoutSeconds: $config['timeout'],
            connectTimeoutSeconds: $config['connect_timeout'],
        );
    }

    /**
     * PRD §16 item 95: this session's live status from OpenWA (`ready`
     * when connected), for `/api/health/whatsapp`. Never throws: an
     * unconfigured session or an unreachable gateway comes back as its
     * own status, which the health check treats as down.
     *
     * ADR-116 2026-11-14 addendum, decision 11: `restricted` is true while
     * WhatsApp limits the session (`restriction` set). A restriction can
     * leave the status `ready` while new chats are blocked, so it is read
     * live here rather than from a webhook that could be missed.
     *
     * @return array{status: string, restricted: bool}
     */
    public function sessionStatus(): array
    {
        if ($this->sessionId === null || $this->apiKey === null) {
            return ['status' => 'not_configured', 'restricted' => false];
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->get("/api/sessions/{$this->sessionId}");
        } catch (ConnectionException) {
            return ['status' => 'unreachable', 'restricted' => false];
        }

        if (! $response->successful()) {
            return ['status' => "http_{$response->status()}", 'restricted' => false];
        }

        return [
            'status' => (string) $response->json('status', 'unknown'),
            'restricted' => is_array($response->json('restriction')),
        ];
    }

    /**
     * ADR-116 2026-11-03 addendum: the `@lid` WhatsApp holds for a phone,
     * asked without sending anything (OpenWA ≥ 0.27.1, OPERATOR key). Null
     * when WhatsApp has none. Throws when OpenWA does not answer (a `503` is
     * an unanswered query, never "no lid"), so the caller's job retries.
     */
    public function lidForPhone(string $phone): ?string
    {
        if ($this->sessionId === null || $this->apiKey === null) {
            throw new RuntimeException('OpenWaClient: session_id/api_key not configured');
        }

        $response = Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->get("/api/sessions/{$this->sessionId}/contacts/{$phone}/lid");

        if ($response->failed()) {
            throw new RuntimeException("OpenWaClient: lid lookup failed with status {$response->status()}");
        }

        $lid = $response->json('lid');

        return is_string($lid) && $lid !== '' ? $lid : null;
    }

    /**
     * The one send: called only by `SendCustomerWhatsAppJob`. Returns
     * OpenWA's message id (ADR-136 decision 33), null if it gave none.
     *
     * ADR-116 2026-11-14 addendum, decision 4: every send carries an
     * `Idempotency-Key`, so OpenWA itself refuses to send a message twice
     * when a retry follows a timeout that did send. Outcomes, read from the
     * response `code` (a plain 409 also means "engine not ready"):
     * - 429 `SEND_PACING_LIMITED` → OpenWaPacingLimitedException (wait);
     * - 400 "not registered" → OpenWaRecipientNotOnWhatsAppException (fail);
     * - `IDEMPOTENCY_OUTCOME_UNKNOWN` / `IDEMPOTENCY_KEY_REUSED` →
     *   OpenWaOutcomeUnknownException (never re-send);
     * - unreachable, timeout, 5xx, any other 400/409/429, or
     *   `IDEMPOTENCY_KEY_IN_PROGRESS` → OpenWaUnavailableException (retry);
     * - any other failure (401/403/404: our config) → RuntimeException.
     */
    public function sendNow(string $chatId, string $text, string $idempotencyKey): ?string
    {
        if ($this->sessionId === null || $this->apiKey === null) {
            throw new RuntimeException('OpenWaClient: session_id/api_key not configured');
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withToken((string) $this->apiKey)
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->post("/api/sessions/{$this->sessionId}/messages/send-text", [
                    'chatId' => $chatId,
                    'text' => $text,
                ]);
        } catch (ConnectionException $e) {
            throw new OpenWaUnavailableException("OpenWaClient: gateway unreachable or timed out: {$e->getMessage()}", 0, $e);
        }

        $status = $response->status();
        $code = (string) $response->json('code', '');
        $message = (string) $response->json('message', '');

        if ($status === 429 && $code === 'SEND_PACING_LIMITED') {
            throw new OpenWaPacingLimitedException(max(1, (int) $response->json('retryAfterSeconds', 900)));
        }

        if (in_array($code, ['IDEMPOTENCY_OUTCOME_UNKNOWN', 'IDEMPOTENCY_KEY_REUSED'], true)) {
            throw new OpenWaOutcomeUnknownException("OpenWA {$status} {$code}: {$message}");
        }

        // OpenWA answers 400 both for an unregistered number and for a session
        // that is still starting, so only the message tells them apart. Baileys
        // says "is not registered"; whatsapp-web.js says "could not resolve the
        // recipient". ponytail: matches OpenWA's wording, so a rewording falls
        // back to retrying (the safe side); recheck these on each OpenWA upgrade.
        if ($status === 400 && preg_match('/is not registered|could not resolve the recipient/i', $message) === 1) {
            throw new OpenWaRecipientNotOnWhatsAppException("The number is not on WhatsApp (OpenWA 400: {$message})");
        }

        if ($status >= 500 || in_array($status, [400, 409, 429], true)) {
            throw new OpenWaUnavailableException("OpenWaClient: send-text answered {$status} {$code}");
        }

        if ($response->failed()) {
            throw new RuntimeException("OpenWaClient: send-text failed with status {$status}");
        }

        $messageId = $response->json('messageId');

        return is_string($messageId) && $messageId !== '' ? $messageId : null;
    }

    /**
     * ADR-136 decision 19: one page of this session's stored message history
     * (`GET /api/sessions/{id}/messages`), read-only, for the history import.
     * Throws when OpenWA does not answer.
     *
     * @param  array<string, scalar>  $query
     * @return list<array<string, mixed>>
     */
    public function messages(array $query): array
    {
        if ($this->sessionId === null || $this->apiKey === null) {
            throw new RuntimeException('OpenWaClient: session_id/api_key not configured');
        }

        $response = Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->get("/api/sessions/{$this->sessionId}/messages", $query);

        if ($response->failed()) {
            throw new RuntimeException("OpenWaClient: messages list failed with status {$response->status()}");
        }

        $messages = $response->json('messages');

        return is_array($messages) ? array_values(array_filter($messages, 'is_array')) : [];
    }
}
