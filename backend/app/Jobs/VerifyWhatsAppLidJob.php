<?php

namespace App\Jobs;

use App\Services\Notification\CustomerWhatsAppInboundService;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * ADR-116 2026-11-03 addendum decision 7: asks OpenWA whether a privacy-id
 * chat is the order's checkout phone, off the webhook thread.
 *
 * Built for a burst (an event, many customers sending order numbers):
 * - its own `whatsapp-lookup` queue, which `supervisor-default` serves only
 *   after `default`, so reseller bot replies never wait behind lookups;
 * - one job per chat for 10 minutes, however many messages it sends;
 * - at most 20 lookups a minute (`whatsapp-lookup` limiter): the rest wait
 *   their turn, so WhatsApp never sees a flood of contact queries.
 * An unanswered lookup throws and is retried; after the last try, or an
 * hour of waiting, nothing is tied and the chat's next order-number
 * message tries again. The order's receipt still reaches the chat as a
 * follower meanwhile.
 */
final class VerifyWhatsAppLidJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Rate-limit releases bump Laravel's attempt count, so only real failures count. */
    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $uniqueFor = 600;

    public function __construct(public readonly int $orderId, public readonly string $lidChat)
    {
        $this->onQueue('whatsapp-lookup');
    }

    public function uniqueId(): string
    {
        return $this->lidChat;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RateLimited('whatsapp-lookup')];
    }

    public function handle(CustomerWhatsAppInboundService $inbound): void
    {
        $inbound->verifyLid($this->orderId, $this->lidChat);
    }
}
