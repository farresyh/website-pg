<?php

namespace App\Services\CsAssistant\Shadow;

use App\Jobs\DraftCsReplyJob;
use App\Models\CsAssistantSettings;
use App\Models\CsBotDraft;
use App\Models\CsIgnoredChat;
use App\Models\CsMessage;
use App\Services\CsAssistant\CsAnswerer;
use App\Services\CsAssistant\CsChatContext;
use App\Services\Llm\ChatModels;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * ADR-136 decisions 11, 18 and 22: in Shadow, the assistant drafts one answer
 * per customer burst and sends nothing. A delayed job per customer message
 * exits when a newer one arrived, so the last message of a burst drafts for
 * the whole burst.
 */
final class CsShadowDrafter
{
    /** A gap this long ends a burst even with no staff reply in between. */
    public const BURST_GAP_MINUTES = 120;

    public function __construct(
        private readonly CsAnswerer $answerer,
        private readonly CsSpend $spend,
        private readonly ChatModels $models,
    ) {}

    /** The module's one entry point from the webhook (decision 33). */
    public function onCustomerMessage(CsMessage $message): void
    {
        $settings = CsAssistantSettings::current();
        if (! $settings->shadowing() || $message->sender !== CsMessage::SENDER_CUSTOMER) {
            return;
        }

        DraftCsReplyJob::dispatch($message->id)->delay(now()->addSeconds($settings->burst_wait_seconds));
    }

    public function draft(int $lastMessageId): ?CsBotDraft
    {
        $settings = CsAssistantSettings::current();
        $last = CsMessage::query()->find($lastMessageId);

        if (! $settings->shadowing() || $last === null || $last->sender !== CsMessage::SENDER_CUSTOMER
            || CsBotDraft::query()->where('last_message_id', $last->id)->exists()
            || CsIgnoredChat::query()->where('chat_hash', $last->chat_hash)->exists()) {
            return null;
        }

        $chat = CsMessage::query()->where('chat_hash', $last->chat_hash)->orderBy('sent_at')->orderBy('id')->get();

        // Decision 22: a newer customer message drafts instead (it has its own job).
        if ($chat->contains(fn (CsMessage $m) => $m->sender === CsMessage::SENDER_CUSTOMER && $this->after($m, $last))) {
            return null;
        }

        // Decision 16: a chat staff started is outreach.
        if ($chat->firstWhere('sender', '!=', CsMessage::SENDER_SYSTEM)?->sender !== CsMessage::SENDER_CUSTOMER) {
            return null;
        }

        $burst = $this->burst($chat, $last);
        $orderNumbers = $burst->flatMap(fn (CsMessage $m) => $m->order_numbers ?? [])->unique()->values()->all();

        if (! $this->hasQuestion($burst)) {
            return null;
        }

        if (! $this->models->has($settings->answer_model) || ! $this->models->configured([$settings->answer_model]) || ! $this->spend->allow()) {
            return null;
        }

        $brand = CsChatContext::brand($orderNumbers, $chat, $burst->first());
        $texts = $burst->map(fn (CsMessage $m) => filled($m->body) ? $m->body : "[{$m->type}]")->values()->all();
        $result = $this->answerer->draft($settings->answer_model, $texts, $brand, CsChatContext::orderFacts($orderNumbers));

        try {
            return CsBotDraft::query()->create([
                'chat_hash' => $last->chat_hash,
                'last_message_id' => $last->id,
                'first_message_id' => $burst->first()->id,
                'question' => implode("\n", $texts),
                'order_numbers' => $orderNumbers ?: null,
                'affiliate_id' => $brand,
                'asked_at' => $burst->first()->sent_at,
                'model' => $settings->answer_model,
                'action' => $result['action'],
                'reply' => $result['reply'],
                'handover_reason' => $result['handover_reason'],
                'category' => $result['category'],
                'entry_ids' => $result['entry_ids'] ?: null,
                'violations' => $result['violations'] ?: null,
                'error' => $result['error'],
                'input_tokens' => $result['input_tokens'],
                'output_tokens' => $result['output_tokens'],
                'cost_usd_micros' => $this->models->costMicros($settings->answer_model, $result['input_tokens'], $result['output_tokens']),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // a redelivered event drafted it first
        }
    }

    /**
     * The customer messages that end at $last: back to the previous staff
     * message, or a two-hour silence. Our own sends (receipts, the status
     * card) do not end a burst.
     *
     * @param  Collection<int, CsMessage>  $chat
     * @return Collection<int, CsMessage>
     */
    private function burst(Collection $chat, CsMessage $last): Collection
    {
        $burst = collect();
        $next = $last;

        foreach ($chat->reverse() as $m) {
            if ($this->after($m, $last)) {
                continue;
            }
            if ($m->sender === CsMessage::SENDER_STAFF || $m->sent_at->lt($next->sent_at->copy()->subMinutes(self::BURST_GAP_MINUTES))) {
                break;
            }
            if ($m->sender === CsMessage::SENDER_CUSTOMER) {
                $burst->prepend($m);
                $next = $m;
            }
        }

        return $burst;
    }

    /**
     * Decision 21: a burst that is only an order number is answered by the
     * status card alone; an image-only burst waits for PR 4's OCR.
     *
     * @param  Collection<int, CsMessage>  $burst
     */
    private function hasQuestion(Collection $burst): bool
    {
        return $burst->contains(fn (CsMessage $m) => filled($m->body)
            && trim((string) preg_replace('/\[ORDER\]|[\s\p{P}]+/u', '', $m->body)) !== '');
    }

    private function after(CsMessage $a, CsMessage $b): bool
    {
        return $a->sent_at->gt($b->sent_at) || ($a->sent_at->eq($b->sent_at) && $a->id > $b->id);
    }
}
