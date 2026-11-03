<?php

namespace App\Services\CsAssistant;

use App\Models\CsIgnoredChat;
use App\Models\CsKnowledgeSuggestion;
use App\Models\CsMessage;
use App\Models\CustomerNotification;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * ADR-136 decision 10, PR 2 (no AI, 2026-11-04 addendum): turns finished CS
 * conversations into pending knowledge suggestions. A run of customer
 * messages is the question; the staff messages after it, until the customer
 * writes again or two hours pass, are the answer. Our own sends are ignored.
 * Re-runnable: one suggestion per question (its first message id).
 */
final class CsKnowledgeSuggester
{
    /** A chat is finished when nothing was said in it for this long. */
    public const QUIET_HOURS = 6;

    /** Decision 18's answer window. */
    public const ANSWER_WINDOW_MINUTES = 120;

    /** Returns how many suggestions were added. */
    public function run(CarbonInterface $now): int
    {
        $ignored = CsIgnoredChat::query()->pluck('chat_hash')->flip();
        $cutoff = $now->subHours(self::QUIET_HOURS);
        $added = 0;

        // ponytail: scans every quiet chat each night (hundreds of rows today, ~20k at 2 years of 10x volume); add a processed-up-to marker per chat if it gets slow.
        $chats = CsMessage::query()
            ->selectRaw('chat_hash, max(sent_at) as last_at')
            ->groupBy('chat_hash')
            ->havingRaw('max(sent_at) <= ?', [$cutoff])
            ->pluck('chat_hash');

        foreach ($chats as $chat) {
            if ($ignored->has($chat)) {
                continue;
            }

            $messages = CsMessage::query()->where('chat_hash', $chat)->orderBy('sent_at')->orderBy('id')->get();

            // Decision 16: a chat staff started is outreach, not a customer question.
            if ($messages->firstWhere('sender', '!=', CsMessage::SENDER_SYSTEM)?->sender !== CsMessage::SENDER_CUSTOMER) {
                continue;
            }

            foreach ($this->pairs($messages) as [$question, $answer]) {
                $added += $this->store($chat, $question, $answer, $messages) ? 1 : 0;
            }
        }

        return $added;
    }

    /**
     * @param  Collection<int, CsMessage>  $messages  one chat, oldest first
     * @return list<array{0: list<CsMessage>, 1: list<CsMessage>}>
     */
    private function pairs(Collection $messages): array
    {
        $pairs = [];
        $question = [];
        $answer = [];

        $close = function () use (&$pairs, &$question, &$answer) {
            if ($question !== [] && collect($answer)->contains(fn (CsMessage $m) => filled($m->body))) {
                $pairs[] = [$question, $answer];
            }
            $question = [];
            $answer = [];
        };

        foreach ($messages as $m) {
            if ($m->sender === CsMessage::SENDER_SYSTEM) {
                continue;
            }

            if ($m->sender === CsMessage::SENDER_CUSTOMER) {
                if ($answer !== []) {
                    $close();
                }
                $question[] = $m;

                continue;
            }

            // Staff.
            $asked = end($question);
            if ($asked !== false && $m->sent_at->lte($asked->sent_at->copy()->addMinutes(self::ANSWER_WINDOW_MINUTES))) {
                $answer[] = $m;
            } elseif ($answer !== []) {
                $close();
            } else {
                $question = [];
            }
        }
        $close();

        return $pairs;
    }

    /**
     * @param  list<CsMessage>  $question
     * @param  list<CsMessage>  $answer
     * @param  Collection<int, CsMessage>  $chat
     */
    private function store(string $chatHash, array $question, array $answer, Collection $chat): bool
    {
        $orderNumbers = collect([...$question, ...$answer])->flatMap(fn (CsMessage $m) => $m->order_numbers ?? [])->unique()->values()->all();

        $suggestion = CsKnowledgeSuggestion::query()->firstOrCreate(
            ['first_message_id' => $question[0]->id],
            [
                'chat_hash' => $chatHash,
                'question' => collect($question)->map(fn (CsMessage $m) => filled($m->body) ? $m->body : "[{$m->type}]")->implode("\n"),
                'answer' => collect($answer)->filter(fn (CsMessage $m) => filled($m->body))->pluck('body')->implode("\n"),
                'order_numbers' => $orderNumbers ?: null,
                'affiliate_id' => $this->brand($orderNumbers, $chat, $question[0]),
                'asked_at' => $question[0]->sent_at,
                'status' => CsKnowledgeSuggestion::STATUS_PENDING,
            ],
        );

        return $suggestion->wasRecentlyCreated;
    }

    /**
     * Decision 9: the order's own brand, else that of our latest send to the
     * chat before the question, else unknown (null).
     *
     * @param  list<string>  $orderNumbers
     * @param  Collection<int, CsMessage>  $chat
     */
    private function brand(array $orderNumbers, Collection $chat, CsMessage $first): ?int
    {
        if ($orderNumbers !== []) {
            $id = Order::query()->whereIn('order_number', $orderNumbers)->value('affiliate_id');
            if ($id !== null) {
                return $id;
            }
        }

        $send = $chat->filter(fn (CsMessage $m) => $m->customer_notification_id !== null && $m->sent_at->lte($first->sent_at))->last()
            ?? $chat->firstWhere('customer_notification_id', '!=', null);

        if ($send === null) {
            return null;
        }

        return Order::query()
            ->whereKey(CustomerNotification::query()->whereKey($send->customer_notification_id)->value('order_id'))
            ->value('affiliate_id');
    }
}
