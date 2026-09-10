<?php

namespace App\Jobs\Reseller;

use App\Models\ResellerWebhookDelivery;
use App\Services\Reseller\Webhook\ResellerWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR-084 PR-3 decision 4: POSTs one `reseller_webhook_deliveries` row to
 * the reseller's endpoint, signed `X-Hub-Signature-256: sha256=<hmac>`
 * (the same scheme `DigiflazzWebhookController` verifies inbound). ~5
 * attempts over ~1 hour, exponential backoff, then the row lands on
 * `exhausted` — the portal's dead-letter view. Polling
 * `GET /v1/orders/{orderNumber}` stays the reseller's backstop.
 *
 * Its own `reseller-webhooks` queue + Horizon supervisor (ADR-048
 * decision 3 isolation) so a slow or hanging reseller endpoint can never
 * head-of-line-block an order job.
 *
 * Dispatched only by `ResellerWebhookDispatcher` — after the fulfillment
 * transaction has committed (`OrderObserver`'s `DB::afterCommit`) or
 * after the wallet-refund ledger write, never from inside a
 * money-critical transaction.
 */
final class DeliverResellerWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** ~5 attempts; `backoff()` spaces them over roughly an hour. */
    public int $tries = 5;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue('reseller-webhooks');
    }

    /** @return list<int> seconds between attempts (1m, 5m, 15m, 30m, then 1h before the final give-up) */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function handle(): void
    {
        $delivery = ResellerWebhookDelivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->status === ResellerWebhookDelivery::STATUS_DELIVERED) {
            return;
        }

        $webhook = $delivery->reseller?->webhook;

        // Endpoint removed or disabled since the event was queued —
        // terminal, not retryable.
        if ($webhook === null || ! $webhook->is_active) {
            $delivery->update(['status' => ResellerWebhookDelivery::STATUS_FAILED, 'next_retry_at' => null]);

            return;
        }

        $rawBody = json_encode($delivery->payload, JSON_THROW_ON_ERROR);
        $attempt = $delivery->attempts + 1;

        Log::withContext(['reseller_webhook_delivery_id' => $delivery->id, 'event_id' => $delivery->event_id]);

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withBody($rawBody, 'application/json')
                ->withHeaders([
                    'X-Hub-Signature-256' => ResellerWebhookService::sign($webhook->secret, $rawBody),
                    'X-Webhook-Event' => $delivery->event,
                    'X-Webhook-Id' => $delivery->event_id,
                ])
                ->post($webhook->url);
        } catch (Throwable $e) {
            $delivery->update([
                'attempts' => $attempt,
                'status' => ResellerWebhookDelivery::STATUS_FAILED,
                'last_response_code' => null,
                'next_retry_at' => $this->nextRetryAt($attempt),
            ]);

            // Rethrow so the queue retries with backoff (or hits failed()).
            throw $e;
        }

        if ($response->successful()) {
            $delivery->update([
                'attempts' => $attempt,
                'status' => ResellerWebhookDelivery::STATUS_DELIVERED,
                'last_response_code' => $response->status(),
                'next_retry_at' => null,
            ]);

            return;
        }

        $delivery->update([
            'attempts' => $attempt,
            'status' => ResellerWebhookDelivery::STATUS_FAILED,
            'last_response_code' => $response->status(),
            'next_retry_at' => $this->nextRetryAt($attempt),
        ]);

        throw new \RuntimeException(
            "Reseller webhook POST to {$webhook->url} returned {$response->status()}"
        );
    }

    public function failed(Throwable $e): void
    {
        $delivery = ResellerWebhookDelivery::query()->find($this->deliveryId);

        $delivery?->update([
            'status' => ResellerWebhookDelivery::STATUS_EXHAUSTED,
            'next_retry_at' => null,
        ]);

        Log::warning('Reseller webhook delivery exhausted all retries', [
            'reseller_webhook_delivery_id' => $this->deliveryId,
            'exception' => $e->getMessage(),
        ]);
    }

    /** null once the last attempt is spent — nothing more is scheduled. */
    private function nextRetryAt(int $attempt): ?Carbon
    {
        $schedule = $this->backoff();

        return isset($schedule[$attempt - 1])
            ? now()->addSeconds($schedule[$attempt - 1])
            : null;
    }
}
