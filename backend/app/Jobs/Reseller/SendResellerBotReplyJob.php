<?php

namespace App\Jobs\Reseller;

use App\Services\OpenWa\OpenWaClient;
use App\Services\OpenWa\OpenWaRecipientNotOnWhatsAppException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\FailOnException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RETIRED by the ADR-116 2026-11-14 addendum: every bot reply is now an
 * outbox row sent by `SendCustomerWhatsAppJob`. Kept one release only, so
 * jobs queued before the deploy still go out; removed with the OpenWA
 * v0.30.0 upgrade. History below.
 *
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
 * `default` queue (ADR-048 addendum, 2026-09-29) — was `orders`, where a
 * burst of orders left replies queued behind every supplier call.
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
        $this->onQueue('default');
    }

    /** PRD §16 item 96: a number not on WhatsApp fails at once; retrying can't help. */
    public function middleware(): array
    {
        return [new FailOnException([OpenWaRecipientNotOnWhatsAppException::class])];
    }

    /**
     * ADR-116 2026-11-14 addendum: retired. New replies go through the
     * outbox; this only drains jobs queued before the deploy, and is removed
     * with the OpenWA v0.30.0 upgrade. The key is the queued job's own id,
     * stable across its retries.
     */
    public function handle(OpenWaClient $openWa): void
    {
        $openWa->sendNow($this->chatId, $this->text, 'legacy-'.($this->job?->uuid() ?? hash('sha256', $this->chatId."\n".$this->text)));
    }

    public function failed(Throwable $e): void
    {
        Log::error('SendResellerBotReplyJob failed — reply lost', [
            'chat_id' => $this->chatId,
            'exception' => $e->getMessage(),
        ]);
    }
}
