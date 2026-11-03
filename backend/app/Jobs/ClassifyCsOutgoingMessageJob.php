<?php

namespace App\Jobs;

use App\Models\CsMessage;
use App\Services\CsAssistant\CsMessageRecorder;
use App\Services\CsAssistant\CsSenderClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * ADR-136 decision 33: stores an outgoing customer-support message once its
 * sender is known. Runs on the low-priority `whatsapp-lookup` lane, never on
 * `whatsapp`, so it can never delay a receipt. While one of our sends to the
 * chat is still in flight it waits; after the last try it counts as staff
 * (the safe side once the assistant exists: it stays quiet).
 */
final class ClassifyCsOutgoingMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public function __construct(
        public readonly string $chatId,
        public readonly string $waMessageId,
        public readonly string $type,
        public readonly ?string $body,
        public readonly int $sentAt,
    ) {
        $this->onQueue('whatsapp-lookup');
    }

    public function handle(CsSenderClassifier $classifier, CsMessageRecorder $recorder): void
    {
        $at = CarbonImmutable::createFromTimestamp($this->sentAt);
        $verdict = $classifier->classify($this->chatId, $this->waMessageId, $this->body, $at);

        if ($verdict['sender'] === CsSenderClassifier::WAIT && $this->attempts() < $this->tries) {
            $this->release(CsMessageRecorder::CLASSIFY_DELAY_SECONDS);

            return;
        }

        $sender = $verdict['sender'] === CsSenderClassifier::WAIT ? CsMessage::SENDER_STAFF : $verdict['sender'];

        $recorder->recordOutbound($this->chatId, $this->waMessageId, $this->type, $this->body, $at, $sender, $verdict['notification_id'], CsMessage::SOURCE_WEBHOOK);
    }
}
