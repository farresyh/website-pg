<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppGroup;
use App\Services\Notification\CustomerWhatsAppInboundService;
use App\Services\OpenWa\OpenWaSessionStatus;
use App\Services\Reseller\Bot\ResellerBotService;
use App\Services\Supplier\Bot\SupplierBotService;
use App\Services\WhatsApp\WhatsAppGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ADR-075 decision 4/5 — the Reseller Bot channel's inbound half. Not
 * behind auth:sanctum: OpenWA is not an admin user.
 *
 * Auth: `X-OpenWA-Signature` HMAC over the raw body with
 * `config('services.openwa.webhook_secret')` — mirrors
 * `DigiflazzWebhookController`'s HMAC-is-the-auth shape, with one real
 * difference confirmed against OpenWA's own docs (docs.open-wa.org) once
 * a real instance was provisioned, 2026-09-05: the header value carries
 * an `{algo}=` prefix before the hex digest (`sha256=<hex>`), not a bare
 * hex string — `docs/adr.md`'s OpenWA provisioning entry has the full
 * story. Header name/prefix are config-driven (`webhook_signature_header`/
 * `_algo`), not hardcoded, so a future OpenWA version changing either
 * doesn't need a code change.
 *
 * Unlike Digiflazz's soft/log-only IP check, this route is additionally
 * hard-restricted to `127.0.0.1` at the nginx layer (ADR-075 decision
 * 4) — OpenWA is co-located on the same droplet, a strictly stronger
 * posture Digiflazz's own cross-internet traffic was never designed
 * for. No IP logic lives in this controller because of that.
 */
class OpenWaWebhookController extends Controller
{
    public function __construct(
        private readonly ResellerBotService $bot,
        private readonly SupplierBotService $supplierBot,
        private readonly WhatsAppGroupService $groups,
        private readonly OpenWaSessionStatus $sessionStatus,
        private readonly CustomerWhatsAppInboundService $customerInbound,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $config = config('services.openwa');
        $secret = $config['webhook_secret'];

        if ($secret === null || $secret === '') {
            Log::warning('Rejected OpenWA webhook: no webhook_secret configured');

            return response()->json(['message' => 'webhook not configured'], 503);
        }

        // OpenWA's real format is "{algo}=<hex>" (e.g. "sha256=abcdef..."),
        // not a bare hex digest — confirmed against docs.open-wa.org.
        $expected = $config['webhook_signature_algo'].'='.hash_hmac($config['webhook_signature_algo'], $request->getContent(), $secret);
        $received = (string) $request->header($config['webhook_signature_header'], '');

        if (! hash_equals($expected, $received)) {
            Log::warning('Rejected OpenWA webhook: invalid signature');

            return response()->json(['message' => 'invalid signature'], 401);
        }

        $event = (string) $request->input('event', '');

        // ADR-116 decision 11: the customer-support session never reaches the
        // reseller bot. Its direct messages go to the opt-in/STOP handler
        // only. An envelope with no sessionId (older OpenWA) is treated as the
        // bot, as before.
        $csSessionId = config('services.openwa.cs_session_id');
        if ($csSessionId !== null && $request->input('sessionId') === $csSessionId) {
            match ($event) {
                'session.status' => $this->recordStatus($request, OpenWaSessionStatus::CUSTOMER_SUPPORT),
                'message.received' => $this->customerInbound->handle((array) $request->input('data', [])),
                default => null,
            };

            return response()->json(['message' => 'ok']);
        }

        // Any other session that isn't the bot's own is dropped, never guessed at.
        $botSessionId = config('services.openwa.session_id');
        $sessionId = $request->input('sessionId');
        if ($sessionId !== null && $botSessionId !== null && $sessionId !== $botSessionId) {
            Log::info('OpenWA webhook: ignored event from an unknown session', ['session_id' => $sessionId]);

            return response()->json(['message' => 'ok']);
        }

        match ($event) {
            'message.received' => $this->handleMessageReceived($request),
            'session.status' => $this->recordStatus($request, OpenWaSessionStatus::RESELLER_BOT),
            default => Log::info('OpenWA webhook: ignored event', ['event' => $event ?: '(none)']),
        };

        return response()->json(['message' => 'ok']);
    }

    /**
     * Group messages only — a direct message to the bot number outside
     * any group is deliberately ignored (ADR-075 decision 3's group-
     * membership trust boundary has no individual-sender identity to
     * even tentatively attribute a DM to).
     *
     * E6 hardening (2026-09-10 reseller-family audit, `docs/build-log.md`):
     * confirmed against OpenWA's own published docs (docs.open-wa.org,
     * checked 2026-09-12) — a boolean `isGroup` field is real and
     * documented, so the field name read below was always correct.
     * docs.open-wa.org/changelog (server >= 0.23.4) later added a more
     * granular `kind` field (`individual`/`group`/`channel`/`status`/
     * `broadcast`/`unknown`) specifically because "`isGroup` boolean
     * could not" separate channel traffic from real group traffic — a
     * gap that matters here (a channel message misread as `isGroup:
     * true` would misroute into the group-trust flow). `kind` is
     * preferred when the payload carries it (newer OpenWA versions);
     * `isGroup` is the fallback for older ones. Still defaults to "not a
     * group" on a wholly unrecognized shape — an unrecognized shape
     * stays a silent drop, not a crash, matching every other
     * defensively-read field in this controller.
     */
    private function handleMessageReceived(Request $request): void
    {
        $data = (array) $request->input('data', []);

        // ADR-125 decision 57: the bot's own posts come back as events too,
        // and must never be parsed as a command.
        if (! $this->isGroupMessage($data) || ($data['fromMe'] ?? false) === true) {
            return;
        }

        $groupId = $data['chatId'] ?? $data['from'] ?? null;
        $text = $data['body'] ?? $data['text'] ?? null;
        $messageId = $data['id'] ?? $data['messageId'] ?? null;

        if (! is_string($groupId) || ! is_string($text) || ! is_string($messageId)) {
            Log::warning('OpenWA webhook: message.received missing expected fields');

            return;
        }

        // ADR-125 decision 11: a supplier's group goes to its own bot;
        // everything else (a reseller's group, an unlinked one) is the
        // reseller bot's, unchanged.
        $group = $this->groups->activeGroup($groupId);
        if ($group?->owner_type === WhatsAppGroup::OWNER_SUPPLIER && $group->supplier !== null) {
            $this->supplierBot->handle($group->supplier, $groupId, $text, $this->sender($data));

            return;
        }

        $this->bot->handle($groupId, $text, $messageId);
    }

    /**
     * Decision 57: who in the group sent it. OpenWA gives `author` (the
     * participant's id, `<digits>@c.us`, or a privacy `@lid`) and, for a
     * privacy id it could resolve, `senderPhone`.
     *
     * @param  array<string, mixed>  $data
     */
    private function sender(array $data): ?string
    {
        $phone = $data['senderPhone'] ?? null;
        if (is_string($phone) && $phone !== '') {
            return $phone;
        }

        $author = $data['author'] ?? null;
        if (! is_string($author) || $author === '') {
            return null;
        }

        return str_ends_with($author, '@c.us') ? substr($author, 0, -5) : $author;
    }

    /** @param  array<string, mixed>  $data */
    private function isGroupMessage(array $data): bool
    {
        if (isset($data['kind']) && is_string($data['kind'])) {
            return $data['kind'] === 'group';
        }

        return (bool) ($data['isGroup'] ?? false);
    }

    private function recordStatus(Request $request, string $session): void
    {
        $status = $request->input('data.status') ?? $request->input('status');

        if (is_string($status)) {
            $this->sessionStatus->record($status, $session);
        }
    }
}
