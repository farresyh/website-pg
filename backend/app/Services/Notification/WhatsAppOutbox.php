<?php

namespace App\Services\Notification;

use App\Jobs\SendCustomerWhatsAppJob;
use App\Models\CustomerNotification;
use App\Support\MalaysiaTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-116 decisions 7/9, generalised by ADR-125 decisions 53-54: the one
 * persisted, de-duplicated, paced outbound WhatsApp queue. Callers decide
 * what to say and whether it must be skipped (CustomerNotificationService
 * for the customer-support session, SupplierGroupNotifier for the bot's
 * supplier groups); this records the row and hands out a send slot per
 * session, so one session's burst never delays the other's pacing.
 *
 * ADR-116 2026-11-14 addendum: the only way any WhatsApp message leaves.
 * The row's `event` decides its lane (decision 6), its lifetime (decision 7)
 * and whether a newer status overtakes it (decision 8); callers never do.
 */
final class WhatsAppOutbox
{
    /** A bot reply to a read command (balance, price list, help, errors): stale after 3 minutes. */
    public const EVENT_BOT_REPLY = 'bot_reply';

    /** A bot reply to a money command (`.order`, `.topupbaki`, `.batalbaki`). */
    public const EVENT_BOT_MONEY_REPLY = 'bot_money_reply';

    /** ADR-076 message 2: an order reached delivered / failed / partially delivered. */
    public const EVENT_BOT_ORDER_UPDATE = 'bot_order_update';

    /** ADR-076 decision 6: a failed bot order's refund. */
    public const EVENT_BOT_REFUND = 'bot_refund';

    /** ADR-135: a `.topupbaki` top-up was paid or ended unpaid. */
    public const EVENT_BOT_TOPUP = 'bot_topup';

    /** ADR-125 decision 58: the supplier bot's answer to a supplier's command. */
    public const EVENT_SUPPLIER_BOT_REPLY = 'supplier_bot_reply';

    /** Decision 6: a reply to someone's command goes at once, on its own lane. */
    public const REPLY_EVENTS = [
        self::EVENT_BOT_REPLY,
        self::EVENT_BOT_MONEY_REPLY,
        self::EVENT_BOT_ORDER_UPDATE,
        self::EVENT_BOT_REFUND,
        self::EVENT_BOT_TOPUP,
        self::EVENT_SUPPLIER_BOT_REPLY,
    ];

    /** Decision 8: messages that report an order's newer status. */
    private const ORDER_STATUS_EVENTS = [self::EVENT_BOT_ORDER_UPDATE, self::EVENT_BOT_REFUND];

    public const LANE_REPLY = 'whatsapp-reply';

    public const LANE_PACED = 'whatsapp';

    private const READ_REPLY_LIFETIME_MINUTES = 3;

    private const LIFETIME_DAYS = 2;

    /** Decision 8: a status message this long after its event says it is late. */
    private const LATE_AFTER_MINUTES = 3;

    public static function laneFor(string $event): string
    {
        return in_array($event, self::REPLY_EVENTS, true) ? self::LANE_REPLY : self::LANE_PACED;
    }

    /** Decision 7: after this the row is no longer worth sending. */
    public static function expiresAt(CustomerNotification $notification): CarbonImmutable
    {
        $created = CarbonImmutable::instance($notification->created_at);

        return $notification->event === self::EVENT_BOT_REPLY
            ? $created->addMinutes(self::READ_REPLY_LIFETIME_MINUTES)
            : $created->addDays(self::LIFETIME_DAYS);
    }

    /** Decision 8: an order's "received" reply is overtaken by any newer status row for that order. */
    public static function isSuperseded(CustomerNotification $notification): bool
    {
        return $notification->event === self::EVENT_BOT_MONEY_REPLY
            && $notification->order_id !== null
            && CustomerNotification::query()
                ->where('order_id', $notification->order_id)
                ->whereIn('event', self::ORDER_STATUS_EVENTS)
                ->where('id', '>', $notification->id)
                ->exists();
    }

    /** Decision 8: the line a late status message ends with, or null when it is on time. */
    public static function lateNote(CustomerNotification $notification): ?string
    {
        if (! in_array($notification->event, [...self::ORDER_STATUS_EVENTS, self::EVENT_BOT_MONEY_REPLY], true)) {
            return null;
        }

        $created = CarbonImmutable::instance($notification->created_at);

        if ($created->diffInMinutes(now()) < self::LATE_AFTER_MINUTES) {
            return null;
        }

        return '⏱ Dikemas kini lewat (status pada '.$created->setTimezone(MalaysiaTime::TIMEZONE)->format('H:i').').';
    }

    /**
     * A bot reply to a group (reseller or supplier), on the reply lane.
     * The dedupe key names what it answers, so a redelivered webhook or a
     * repeated status event never sends it twice.
     */
    public function reply(string $event, string $dedupeKey, string $groupId, string $message, ?int $orderId = null): void
    {
        $this->enqueue(
            session: CustomerNotification::SESSION_BOT,
            event: $event,
            dedupeKey: $dedupeKey,
            message: $message,
            chatId: $groupId,
            orderId: $orderId,
        );
    }

    /** The customer-support key predates the bot session; kept so its pacing is unchanged. */
    private const SLOT_KEYS = [
        CustomerNotification::SESSION_CUSTOMER_SUPPORT => 'whatsapp:next-send-slot',
        CustomerNotification::SESSION_BOT => 'whatsapp:next-send-slot:bot',
    ];

    /**
     * Sent, queued or failed: never again for the same dedupe key. Only a
     * row skipped for a reason that no longer applies gets its one real
     * chance, via a conditional update so two callers can't both revive it.
     */
    public function enqueue(
        string $session,
        string $event,
        string $dedupeKey,
        string $message,
        ?string $phone = null,
        ?string $chatId = null,
        ?int $orderId = null,
        ?int $voucherId = null,
        ?string $skipReason = null,
        // ADR-126 decision 10: the earliest a message may go (a paced joki
        // progress update); null = the session's next pacing slot.
        ?DateTimeInterface $notBefore = null,
    ): void {
        try {
            $notification = CustomerNotification::query()->create([
                'session' => $session,
                'event' => $event,
                'dedupe_key' => $dedupeKey,
                'order_id' => $orderId,
                'voucher_id' => $voucherId,
                'phone' => $phone,
                'chat_id' => $chatId,
                'message' => $message,
                'status' => $skipReason === null ? CustomerNotification::STATUS_QUEUED : CustomerNotification::STATUS_SKIPPED,
                'error' => $skipReason,
            ]);
        } catch (UniqueConstraintViolationException) {
            if ($skipReason !== null) {
                return;
            }

            $revived = CustomerNotification::query()
                ->where('dedupe_key', $dedupeKey)
                ->where('status', CustomerNotification::STATUS_SKIPPED)
                ->update(['status' => CustomerNotification::STATUS_QUEUED, 'phone' => $phone, 'chat_id' => $chatId, 'message' => $message, 'error' => null, 'attempts' => 0]);

            if ($revived === 0) {
                return;
            }

            $notification = CustomerNotification::query()->where('dedupe_key', $dedupeKey)->firstOrFail();
        }

        if ($skipReason === null) {
            $lane = self::laneFor($event);
            $due = match (true) {
                $notBefore !== null && $notBefore > now() => CarbonImmutable::instance($notBefore),
                $lane === self::LANE_REPLY => CarbonImmutable::now(),
                default => $this->nextSendSlot($session),
            };

            $notification->update(['due_at' => $due]);

            // Decision 2: after commit, so the job never runs before its row exists.
            SendCustomerWhatsAppJob::dispatch($notification->id)->onQueue($lane)->delay($due)->afterCommit();
        }
    }

    /**
     * ADR-116 decision 7: send times 10–30s apart (config knobs) within a
     * session, so a burst of events never becomes a burst of messages.
     * Reserved under a lock, so two concurrent callers can't take one slot.
     */
    private function nextSendSlot(string $session): CarbonImmutable
    {
        $key = self::SLOT_KEYS[$session];

        return Cache::lock($key.':lock', 5)->block(5, function () use ($key) {
            $now = CarbonImmutable::now();
            $reserved = Cache::get($key);
            $slot = $reserved !== null && $reserved > $now->getTimestamp()
                ? CarbonImmutable::createFromTimestamp($reserved)
                : $now;

            $gap = random_int(
                (int) config('services.openwa.notification_gap_min_seconds'),
                (int) config('services.openwa.notification_gap_max_seconds'),
            );
            Cache::put($key, $slot->getTimestamp() + $gap, 86400);

            return $slot;
        });
    }
}
