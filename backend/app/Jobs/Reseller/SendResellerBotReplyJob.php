<?php

namespace App\Jobs\Reseller;

use App\Services\OpenWa\OpenWaClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * E8 hardening (2026-09-10 reseller-family audit, `docs/build-log.md`):
 * `OpenWaClient::sendText()` used to POST inline, synchronously, with no
 * retry — if OpenWA was down at the moment a reply went out, the reply
 * was silently lost (logged, never re-attempted). The order/top-up/log
 * write this reply is reporting has already committed by the time a
 * reply is sent, so this job only ever risks a lost *notification*, never
 * a lost order or a double-charge — same best-effort posture as
 * `PurgeNextCatalogCache`, whose retry shape (3 tries, short backoff,
 * `failed()` logs and stops) this mirrors.
 *
 * `orders` queue — same one `SendResellerBotOrderNotification` (message
 * 2 of the order lifecycle) already runs on; this is the same class of
 * low-volume, best-effort reseller-bot notification, not worth a
 * dedicated Horizon supervisor of its own.
 */
final class SendResellerBotReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 20];

    public function __construct(
        public readonly string $chatId,
        public readonly string $text,
    ) {
        $this->onQueue('orders');
    }

    public function handle(OpenWaClient $openWa): void
    {
        $openWa->sendNow($this->chatId, $this->text);
    }

    public function failed(Throwable $e): void
    {
        Log::error('SendResellerBotReplyJob exhausted all retries — reply lost', [
            'chat_id' => $this->chatId,
            'exception' => $e->getMessage(),
        ]);
    }
}
