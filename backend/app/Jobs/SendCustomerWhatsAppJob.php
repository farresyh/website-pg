<?php

namespace App\Jobs;

use App\Models\CustomerNotification;
use App\Services\OpenWa\OpenWaClient;
use App\Services\OpenWa\OpenWaPacingLimitedException;
use App\Services\OpenWa\OpenWaRecipientNotOnWhatsAppException;
use App\Services\Notification\CustomerNotificationService;
use App\Services\Supplier\Manual\SupplierGroupNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\FailOnException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR-116 decision 7: sends one CustomerNotification on the dedicated
 * one-worker `whatsapp` lane, from the `customer-support` session or, for a
 * supplier group post (ADR-125 decision 54), the bot session.
 *
 * - OpenWA pacing refusal (429 SEND_PACING_LIMITED): released for
 *   `retryAfterSeconds`. It doesn't count as an exception, and the row's
 *   own `attempts` is undone.
 * - Any other failure: up to 3 tries with backoff, then `failed()` marks the row
 *   so the admin sees it and contacts the customer by hand.
 * - A row that is no longer `queued` (already sent by an earlier try whose
 *   ack was lost, or skipped) is left alone, so a message never goes twice.
 */
final class SendCustomerWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Only real exceptions count toward failing. A pacing release also bumps
     * Laravel's attempt counter, so `$tries` would fail a merely-paced message;
     * `retryUntil()` bounds the whole thing instead. OpenWA's daily cap can
     * hold a message for up to a day.
     */
    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDays(2);
    }

    public function __construct(public readonly int $notificationId)
    {
        $this->onQueue('whatsapp');
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

        // ADR-125 decision 52: a supplier post is finished here, so its login
        // link is minted at send time and never stored. A post or reminder for
        // an attempt that is no longer waiting is skipped, not sent. ADR-126
        // decision 10: a joki progress message is worded here too, with the
        // latest rank, and skipped if it no longer applies.
        $isBot = $notification->session === CustomerNotification::SESSION_BOT;
        $text = $isBot
            ? app(SupplierGroupNotifier::class)->prepare($notification)
            : app(CustomerNotificationService::class)->prepare($notification);

        if ($text === null) {
            $notification->update(['status' => CustomerNotification::STATUS_SKIPPED, 'error' => $isBot
                ? 'The order is no longer waiting for this supplier'
                : 'No longer applies (order finished or the customer stopped updates)']);

            return;
        }

        $notification->increment('attempts');

        try {
            ($isBot ? app(OpenWaClient::class) : OpenWaClient::customerSupport())
                ->sendNow($notification->chat_id ?? $notification->phone.'@c.us', $text);
        } catch (OpenWaPacingLimitedException $e) {
            Log::info('Customer WhatsApp paced by OpenWA, will retry', [
                'customer_notification_id' => $notification->id,
                'retry_after_seconds' => $e->retryAfterSeconds,
            ]);
            $notification->decrement('attempts');
            $this->release($e->retryAfterSeconds);

            return;
        }

        $notification->update([
            'status' => CustomerNotification::STATUS_SENT,
            'sent_at' => now(),
            'error' => null,
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
}
