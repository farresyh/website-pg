<?php

namespace App\Services\CsAssistant;

use App\Jobs\ClassifyCsOutgoingMessageJob;
use App\Models\CsMessage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * ADR-136 decisions 8 and 19: the one writer of `cs_messages`. Keeps only
 * personal chats of the customer-support session, masked, once per message
 * (a redelivered webhook or a re-run import is a no-op).
 */
final class CsMessageRecorder
{
    /** Seconds before an outgoing message is classified: the webhook can beat our send's response. */
    public const CLASSIFY_DELAY_SECONDS = 10;

    /** @param  array<string, mixed>  $data  OpenWA `message.received` data */
    public function recordInbound(array $data): void
    {
        $chatId = $this->chatId($data, 'from');
        $id = $this->messageId($data);

        if ($chatId === null || $id === null || ($data['fromMe'] ?? false) === true) {
            return;
        }

        $this->store($chatId, $id, 'in', CsMessage::SENDER_CUSTOMER, $this->type($data), $this->body($data), $this->at($data), null, CsMessage::SOURCE_WEBHOOK);
    }

    /** @param  array<string, mixed>  $data  OpenWA `message.sent` data */
    public function queueOutbound(array $data): void
    {
        $chatId = $this->chatId($data, 'to');
        $id = $this->messageId($data);

        if ($chatId === null || $id === null) {
            return;
        }

        ClassifyCsOutgoingMessageJob::dispatch($chatId, $id, $this->type($data), $this->body($data), $this->at($data)->getTimestamp())
            ->delay(now()->addSeconds(self::CLASSIFY_DELAY_SECONDS));
    }

    /** Called once the sender is known (ClassifyCsOutgoingMessageJob, the history import). */
    public function recordOutbound(string $chatId, string $id, string $type, ?string $body, CarbonInterface $at, string $sender, ?int $notificationId, string $source): void
    {
        $this->store($chatId, $id, 'out', $sender, $type, $body, $at, $notificationId, $source);
    }

    /** @param  array<string, mixed>  $row  one message from OpenWA's `GET /messages` */
    public function recordImported(array $row, CsSenderClassifier $classifier): bool
    {
        $incoming = ($row['direction'] ?? null) === 'incoming';
        $data = $row + ['kind' => str_ends_with((string) ($row['chatId'] ?? ''), '@g.us') ? 'group' : 'individual'];
        $chatId = $this->chatId($data, $incoming ? 'from' : 'to');
        $id = $this->messageId($data);

        if ($chatId === null || $id === null) {
            return false;
        }

        $body = $this->body($data);
        $at = $this->at($data);

        if ($incoming) {
            return $this->store($chatId, $id, 'in', CsMessage::SENDER_CUSTOMER, $this->type($data), $body, $at, null, CsMessage::SOURCE_IMPORT);
        }

        $verdict = $classifier->classify($chatId, $id, $body, $at);
        $sender = $verdict['sender'] === CsSenderClassifier::WAIT ? CsMessage::SENDER_STAFF : $verdict['sender'];

        return $this->store($chatId, $id, 'out', $sender, $this->type($data), $body, $at, $verdict['notification_id'], CsMessage::SOURCE_IMPORT);
    }

    /** A keyed hash, so a stored row never holds the phone or privacy id. */
    public static function chatHash(string $chatId): string
    {
        return hash_hmac('sha256', strtolower($chatId), (string) config('app.key'));
    }

    private function store(string $chatId, string $id, string $direction, string $sender, string $type, ?string $body, CarbonInterface $at, ?int $notificationId, string $source): bool
    {
        if (CsMessage::query()->where('wa_message_id', $id)->exists()) {
            return false;
        }

        $orders = $body === null ? [] : CsTextRedactor::orderNumbers($body);

        try {
            CsMessage::query()->create([
                'wa_message_id' => $id,
                'chat_hash' => self::chatHash($chatId),
                'direction' => $direction,
                'sender' => $sender,
                'type' => $type,
                // Our own sends keep no text: it is in customer_notifications (names, codes).
                'body' => $sender === CsMessage::SENDER_SYSTEM || $body === null ? null : CsTextRedactor::redact($body),
                'order_numbers' => $orders === [] ? null : $orders,
                'customer_notification_id' => $notificationId,
                'source' => $source,
                'sent_at' => $at,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false; // a concurrent delivery of the same message stored it first
        }

        return true;
    }

    /**
     * The personal chat this message belongs to, or null for groups,
     * channels, broadcasts and status updates.
     *
     * @param  array<string, mixed>  $data
     */
    private function chatId(array $data, string $fallback): ?string
    {
        $kind = $data['kind'] ?? null;
        if (is_string($kind) ? $kind !== 'individual' : (bool) ($data['isGroup'] ?? false)) {
            return null;
        }

        $chatId = $data['chatId'] ?? $data[$fallback] ?? null;

        return is_string($chatId) && preg_match('/^\d+@(c\.us|lid|s\.whatsapp\.net)$/', $chatId) === 1 ? $chatId : null;
    }

    /** @param  array<string, mixed>  $data */
    private function messageId(array $data): ?string
    {
        $id = $data['waMessageId'] ?? $data['id'] ?? $data['messageId'] ?? null;

        return is_string($id) && $id !== '' ? CsSenderClassifier::normalizeId($id) : null;
    }

    /** @param  array<string, mixed>  $data */
    private function body(array $data): ?string
    {
        $body = trim((string) ($data['body'] ?? $data['text'] ?? ''));

        return $body === '' ? null : $body;
    }

    /** @param  array<string, mixed>  $data */
    private function type(array $data): string
    {
        $type = $data['type'] ?? 'text';

        return is_string($type) && $type !== '' ? substr($type, 0, 20) : 'text';
    }

    /** @param  array<string, mixed>  $data */
    private function at(array $data): CarbonImmutable
    {
        $ts = $data['timestamp'] ?? null;

        return is_numeric($ts) && (int) $ts > 0 ? CarbonImmutable::createFromTimestamp((int) $ts) : CarbonImmutable::now();
    }
}
