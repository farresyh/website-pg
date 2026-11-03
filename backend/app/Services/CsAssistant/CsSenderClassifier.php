<?php

namespace App\Services\CsAssistant;

use App\Models\CsMessage;
use App\Models\CustomerNotification;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * ADR-136 decision 33: who sent an outgoing message on the customer-support
 * number. Ours (a CustomerNotification) or staff typing on the phone or in
 * the OpenWA dashboard. Getting this wrong later pauses the assistant after
 * every receipt (ours read as staff), so it is decided here only:
 *
 * 1. the OpenWA message id we stored at send time (engines differ in prefix,
 *    so the last `_` segment is compared);
 * 2. else the same chat and exact text within 2 minutes of our send;
 * 3. else, if one of our sends to that chat is still in flight, WAIT (the
 *    webhook can beat the send's own response);
 * 4. else staff.
 */
final class CsSenderClassifier
{
    public const WAIT = 'wait';

    private const WINDOW_SECONDS = 120;

    /** @return array{sender: string, notification_id: ?int} */
    public function classify(string $chatId, string $waMessageId, ?string $body, CarbonInterface $at): array
    {
        $id = self::normalizeId($waMessageId);

        $byId = $this->forChat($chatId)
            ->whereNotNull('wa_message_id')
            ->where(fn (Builder $q) => $q->where('wa_message_id', $id)->orWhere('wa_message_id', 'like', '%_'.$id))
            ->value('id');
        if ($byId !== null) {
            return ['sender' => CsMessage::SENDER_SYSTEM, 'notification_id' => (int) $byId];
        }

        if ($body !== null && $body !== '') {
            $byText = $this->forChat($chatId)
                ->where('status', CustomerNotification::STATUS_SENT)
                ->whereBetween('sent_at', [$at->copy()->subSeconds(self::WINDOW_SECONDS), $at->copy()->addSeconds(self::WINDOW_SECONDS)])
                ->where('message', $body)
                ->value('id');
            if ($byText !== null) {
                return ['sender' => CsMessage::SENDER_SYSTEM, 'notification_id' => (int) $byText];
            }
        }

        $inFlight = $at->diffInSeconds(now(), true) <= self::WINDOW_SECONDS
            && $this->forChat($chatId)
                ->where('status', CustomerNotification::STATUS_QUEUED)
                ->where('attempts', '>', 0)
                ->exists();

        return ['sender' => $inFlight ? self::WAIT : CsMessage::SENDER_STAFF, 'notification_id' => null];
    }

    /** `true_6012…@c.us_3EB0AB` (whatsapp-web.js) and `3EB0AB` (Baileys) are one message. */
    public static function normalizeId(string $waMessageId): string
    {
        $pos = strrpos($waMessageId, '_');

        return $pos === false ? $waMessageId : substr($waMessageId, $pos + 1);
    }

    /** @return Builder<CustomerNotification> */
    private function forChat(string $chatId): Builder
    {
        $phone = str_ends_with($chatId, '@c.us') ? substr($chatId, 0, -5) : null;

        return CustomerNotification::query()
            ->where('session', CustomerNotification::SESSION_CUSTOMER_SUPPORT)
            ->where(fn (Builder $q) => $phone !== null
                ? $q->where('chat_id', $chatId)->orWhere('phone', $phone)
                : $q->where('chat_id', $chatId));
    }
}
