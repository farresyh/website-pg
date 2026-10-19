<?php

namespace App\Services\Notification;

use App\Jobs\SendCustomerWhatsAppJob;
use App\Models\CustomerNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-116 decisions 7/9, generalised by ADR-125 decisions 53-54: the one
 * persisted, de-duplicated, paced outbound WhatsApp queue. Callers decide
 * what to say and whether it must be skipped (CustomerNotificationService
 * for the customer-support session, SupplierGroupNotifier for the bot's
 * supplier groups); this records the row and hands out a send slot per
 * session, so one session's burst never delays the other's pacing.
 */
final class WhatsAppOutbox
{
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
                ->update(['status' => CustomerNotification::STATUS_QUEUED, 'phone' => $phone, 'chat_id' => $chatId, 'message' => $message, 'error' => null]);

            if ($revived === 0) {
                return;
            }

            $notification = CustomerNotification::query()->where('dedupe_key', $dedupeKey)->firstOrFail();
        }

        if ($skipReason === null) {
            SendCustomerWhatsAppJob::dispatch($notification->id)->delay($this->nextSendSlot($session));
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
