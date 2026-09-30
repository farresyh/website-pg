<?php

namespace App\Jobs;

use App\Models\CustomerNotification;
use App\Services\OpenWa\OpenWaClient;
use App\Services\OpenWa\OpenWaPacingLimitedException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR-116 decision 7: sends one CustomerNotification from the
 * `customer-support` session, on the dedicated one-worker `whatsapp` lane.
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

    public function handle(): void
    {
        $notification = CustomerNotification::query()->find($this->notificationId);

        if ($notification === null || $notification->status !== CustomerNotification::STATUS_QUEUED) {
            return;
        }

        $notification->increment('attempts');

        try {
            OpenWaClient::customerSupport()->sendNow($notification->phone.'@c.us', $notification->message);
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

        Log::error('Customer WhatsApp notification failed', [
            'customer_notification_id' => $this->notificationId,
            'exception' => $e->getMessage(),
        ]);
    }
}
