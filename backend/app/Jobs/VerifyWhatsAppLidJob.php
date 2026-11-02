<?php

namespace App\Jobs;

use App\Services\Notification\CustomerWhatsAppInboundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * ADR-116 2026-11-03 addendum decision 7: asks OpenWA whether a privacy-id
 * chat is the order's checkout phone, off the webhook thread. An unanswered
 * lookup throws and is retried; after the last try nothing is tied, and the
 * chat's next order-number message tries again.
 */
final class VerifyWhatsAppLidJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $orderId, public readonly string $lidChat)
    {
        $this->onQueue('default');
    }

    public function handle(CustomerWhatsAppInboundService $inbound): void
    {
        $inbound->verifyLid($this->orderId, $this->lidChat);
    }
}
