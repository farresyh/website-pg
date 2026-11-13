<?php

namespace App\Jobs;

use App\Models\CustomerNotification;
use App\Services\Notification\CustomerNotificationService;
use App\Services\Notification\WhatsAppOutbox;
use App\Services\OpenWa\OpenWaClient;
use App\Services\OpenWa\OpenWaOutcomeUnknownException;
use App\Services\OpenWa\OpenWaPacingLimitedException;
use App\Services\OpenWa\OpenWaRecipientNotOnWhatsAppException;
use App\Services\OpenWa\OpenWaUnavailableException;
use App\Services\Rebate\RebateNotifier;
use App\Services\Supplier\Manual\SupplierGroupNotifier;
use App\Services\Supplier\Manual\SupplierLoginLinkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\FailOnException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR-116 decision 7: sends one outbox row (CustomerNotification), the only
 * WhatsApp send in the app (2026-11-14 addendum, decision 1). The lane is
 * chosen by WhatsAppOutbox: `whatsapp-reply` for replies to a command,
 * `whatsapp` (paced, one worker) for everything we start.
 *
 * - A row past its lifetime is `expired`; an order's "received" reply
 *   overtaken by a newer status is `superseded` (decisions 7, 8).
 * - The first attempt renders the text and freezes it on the row, so every
 *   retry sends the same text under the same Idempotency-Key (decisions 3,
 *   4). A supplier post's login link is the exception: it is minted per
 *   attempt and never stored (ADR-125 decision 52), so its retry may meet
 *   `IDEMPOTENCY_KEY_REUSED` and end `unknown`.
 * - OpenWA pacing refusal: released for `retryAfterSeconds`, `due_at` moved,
 *   the row's own `attempts` undone.
 * - Gateway unavailable (down, timeout, 5xx, key in progress): released with
 *   a growing delay until the lifetime ends (decision 5); `due_at` stays, so
 *   the stuck-message alert sees it. Neither counts as an exception.
 * - Outcome unknown: `unknown`, never re-sent (decision 4).
 * - Any other failure: up to 3 tries, then `failed()` marks the row.
 */
final class SendCustomerWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Only real exceptions count toward failing. A release also bumps
     * Laravel's attempt counter, so `$tries` would fail a merely-waiting
     * message; `retryUntil()` bounds the whole thing instead.
     */
    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    private const UNAVAILABLE_FIRST_DELAY_SECONDS = 10;

    private const UNAVAILABLE_MAX_DELAY_SECONDS = 300;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDays(2);
    }

    public function __construct(public readonly int $notificationId)
    {
        $this->onQueue(WhatsAppOutbox::LANE_PACED);
    }

    /** PRD §16 item 96: a number not on WhatsApp fails at once; retrying can't help. */
    public function middleware(): array
    {
        return [new FailOnException([OpenWaRecipientNotOnWhatsAppException::class])];
    }

    public function handle(): void
    {
        $notification = CustomerNotification::query()->find($this->notificationId);

        if ($notification === null || $notification->status !== CustomerNotification::STATUS_QUEUED) {
            return;
        }

        if (now()->greaterThan(WhatsAppOutbox::expiresAt($notification))) {
            $notification->update(['status' => CustomerNotification::STATUS_EXPIRED, 'error' => 'Not sent within its lifetime']);

            return;
        }

        if (WhatsAppOutbox::isSuperseded($notification)) {
            $notification->update(['status' => CustomerNotification::STATUS_SUPERSEDED, 'error' => null]);

            return;
        }

        $text = $this->text($notification);

        if ($text === null) {
            $notification->update(['status' => CustomerNotification::STATUS_SKIPPED, 'error' => $notification->session === CustomerNotification::SESSION_BOT
                ? 'The order is no longer waiting for this supplier'
                : 'No longer applies (order finished or the customer stopped updates)']);

            return;
        }

        $client = $this->client($notification);

        // Tests and local dev run without OpenWA: nothing to send to, as the
        // old bot reply path did (a no-op), never an error in the caller.
        if (! $client->isConfigured()) {
            $notification->update(['status' => CustomerNotification::STATUS_SKIPPED, 'error' => 'WhatsApp session not configured']);

            return;
        }

        $notification->increment('attempts');
        $chatId = $notification->chat_id ?? $notification->phone.'@c.us';

        try {
            $waMessageId = $client->sendNow($chatId, $text, self::idempotencyKey($notification, $chatId));
        } catch (OpenWaPacingLimitedException $e) {
            Log::info('WhatsApp send paced by OpenWA, will retry', [
                'customer_notification_id' => $notification->id,
                'retry_after_seconds' => $e->retryAfterSeconds,
            ]);
            $notification->decrement('attempts');
            $notification->update(['due_at' => now()->addSeconds($e->retryAfterSeconds)]);
            $this->release($e->retryAfterSeconds);

            return;
        } catch (OpenWaUnavailableException $e) {
            $delay = min(self::UNAVAILABLE_MAX_DELAY_SECONDS, self::UNAVAILABLE_FIRST_DELAY_SECONDS * 2 ** min(10, $notification->attempts - 1));
            Log::warning('WhatsApp gateway unavailable, will retry', [
                'customer_notification_id' => $notification->id,
                'retry_in_seconds' => $delay,
                'error' => $e->getMessage(),
            ]);
            $notification->update(['error' => mb_substr($e->getMessage(), 0, 1000)]);
            $this->release($delay);

            return;
        } catch (OpenWaOutcomeUnknownException $e) {
            $notification->update(['status' => CustomerNotification::STATUS_UNKNOWN, 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            Log::warning('WhatsApp send outcome unknown, not re-sent', ['customer_notification_id' => $notification->id]);

            return;
        }

        $notification->update([
            'status' => CustomerNotification::STATUS_SENT,
            'sent_at' => now(),
            'error' => null,
            'wa_message_id' => $waMessageId, // ADR-136 decision 33
        ]);
    }

    public function failed(Throwable $e): void
    {
        CustomerNotification::query()
            ->whereKey($this->notificationId)
            ->where('status', CustomerNotification::STATUS_QUEUED)
            ->update(['status' => CustomerNotification::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000)]);

        Log::error('WhatsApp notification failed', [
            'customer_notification_id' => $this->notificationId,
            'exception' => $e->getMessage(),
        ]);
    }

    /**
     * Decision 4: stable across every retry of this row (the stored message
     * is the frozen text, or a supplier post's template), and new when a
     * revived row gets a new message or recipient.
     */
    public static function idempotencyKey(CustomerNotification $notification, string $chatId): string
    {
        return 'cn-'.$notification->id.'-'.substr(hash('sha256', $chatId."\n".$notification->message), 0, 16);
    }

    /**
     * Decision 3: render once, then freeze. ADR-125 decision 52: a supplier
     * post finishes here, its login link minted at send time and never
     * stored, so a template holding the link placeholder is rendered on every
     * attempt and left as the template. ADR-126 decision 10: a joki progress
     * message is worded here with the latest rank, or skipped if it no longer
     * applies. Null means "no longer applies".
     */
    private function text(CustomerNotification $notification): ?string
    {
        $holdsSecret = str_contains($notification->message, SupplierLoginLinkService::PLACEHOLDER);

        if ($notification->attempts > 0 && ! $holdsSecret) {
            return $this->withLateNote($notification, $notification->message);
        }

        $text = match (true) {
            $notification->session !== CustomerNotification::SESSION_BOT => app(CustomerNotificationService::class)->prepare($notification),
            // ADR-139: a rebate message to a reseller's group is final when queued.
            RebateNotifier::isRebateEvent($notification->event) => $notification->message,
            WhatsAppOutbox::laneFor($notification->event) === WhatsAppOutbox::LANE_REPLY => $notification->message,
            default => app(SupplierGroupNotifier::class)->prepare($notification),
        };

        if ($text !== null && ! $holdsSecret && $text !== $notification->message) {
            $notification->update(['message' => $text]);
        }

        return $text === null ? null : $this->withLateNote($notification, $text);
    }

    /** Decision 8: added at send time, outside the frozen text, so it never changes the key. */
    private function withLateNote(CustomerNotification $notification, string $text): string
    {
        $note = WhatsAppOutbox::lateNote($notification);

        return $note === null ? $text : $text."\n\n".$note;
    }

    private function client(CustomerNotification $notification): OpenWaClient
    {
        return $notification->session === CustomerNotification::SESSION_BOT ? app(OpenWaClient::class) : OpenWaClient::customerSupport();
    }
}
