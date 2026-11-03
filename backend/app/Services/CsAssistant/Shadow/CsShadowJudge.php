<?php

namespace App\Services\CsAssistant\Shadow;

use App\Models\CsAssistantSettings;
use App\Models\CsBotDraft;
use App\Models\CsMessage;
use App\Services\Llm\ChatModelException;
use App\Services\Llm\ChatModels;
use App\Services\Llm\ChatRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * ADR-136 decision 18: each night a draft is paired with what staff actually
 * replied (staff messages after the burst, until the customer writes again,
 * within two hours) and graded by a different, stronger model (decision 33).
 * A draft staff never answered is closed ungraded and not counted; an answer
 * that is only an image is "unclear" for the admin.
 */
final class CsShadowJudge
{
    public const ANSWER_WINDOW_MINUTES = 120;

    public function __construct(
        private readonly ChatModels $models,
        private readonly CsSpend $spend,
    ) {}

    /** @return Collection<int, CsBotDraft> drafts whose answer window has closed */
    public function due(CarbonInterface $now): Collection
    {
        return CsBotDraft::query()
            ->whereNull('judged_at')
            ->where('asked_at', '<=', $now->copy()->subMinutes(self::ANSWER_WINDOW_MINUTES))
            ->orderBy('id')
            ->get();
    }

    public function judge(CsBotDraft $draft): void
    {
        if ($draft->judged_at !== null) {
            return;
        }

        $answer = $this->staffAnswer($draft);
        if ($answer === null) {
            $draft->update(['judged_at' => now()]);

            return;
        }

        $text = $answer->filter(fn (CsMessage $m) => filled($m->body))->pluck('body')->implode("\n");
        if ($text === '') {
            $draft->update(['staff_answer' => '['.$answer->pluck('type')->unique()->implode(', ').']', 'grade' => 'unclear', 'judged_at' => now()]);

            return;
        }

        $judge = CsAssistantSettings::current()->judge_model;
        if (! $this->models->has($judge) || ! $this->models->configured([$judge]) || ! $this->spend->allow()) {
            return; // tried again tomorrow night
        }

        try {
            $response = $this->models->make($judge)->complete(new ChatRequest(
                system: $this->system(),
                messages: [['role' => 'user', 'text' => $this->pair($draft, $text)]],
                jsonSchema: [
                    'type' => 'object',
                    'properties' => [
                        'grade' => ['type' => 'string', 'enum' => array_values(array_diff(CsBotDraft::GRADES, ['unclear']))],
                        'note' => ['type' => 'string'],
                    ],
                    'required' => ['grade', 'note'],
                ],
            ));
        } catch (ChatModelException) {
            return; // tried again tomorrow night
        }

        $result = json_decode((string) $response->text, true);
        $grade = is_array($result) && in_array($result['grade'] ?? null, CsBotDraft::GRADES, true) ? $result['grade'] : null;

        $draft->update([
            'staff_answer' => $text,
            'grade' => $grade,
            'grade_note' => $grade === null ? 'The judge did not return a valid grade.' : mb_substr((string) ($result['note'] ?? ''), 0, 1000),
            'judge_model' => $judge,
            'judge_cost_usd_micros' => $this->models->costMicros($judge, $response->inputTokens, $response->outputTokens),
            'judged_at' => now(),
        ]);
    }

    /** @return Collection<int, CsMessage>|null staff's reply to the burst, null when there was none */
    private function staffAnswer(CsBotDraft $draft): ?Collection
    {
        $last = CsMessage::query()->find($draft->last_message_id);
        if ($last === null) {
            return null;
        }

        $after = CsMessage::query()
            ->where('chat_hash', $draft->chat_hash)
            ->where('sender', '!=', CsMessage::SENDER_SYSTEM)
            ->where(fn ($q) => $q->where('sent_at', '>', $last->sent_at)->orWhere(fn ($q) => $q->where('sent_at', $last->sent_at)->where('id', '>', $last->id)))
            ->where('sent_at', '<=', $last->sent_at->copy()->addMinutes(self::ANSWER_WINDOW_MINUTES))
            ->orderBy('sent_at')->orderBy('id')
            ->get();

        $answer = $after->takeWhile(fn (CsMessage $m) => $m->sender === CsMessage::SENDER_STAFF);

        return $answer->isEmpty() ? null : $answer->values();
    }

    private function pair(CsBotDraft $draft, string $staff): string
    {
        $bot = $draft->action === 'handover'
            ? "HANDED OVER to staff (reason: {$draft->handover_reason}).".($draft->reply ? " It told the customer: {$draft->reply}" : '')
            : "REPLIED: {$draft->reply}";

        return "Customer (personal details masked):\n{$draft->question}\n\nAssistant's draft (never sent):\n{$bot}\n\nWhat staff actually replied:\n{$staff}";
    }

    private function system(): string
    {
        return <<<'PROMPT'
        You grade a customer-support assistant's draft for a game top-up store in Malaysia against what the store's staff actually replied on WhatsApp. Staff know the business; treat their reply as the reference for facts, but not for tone.

        Grades:
        - same: the draft gives the customer the same information or outcome as staff, in any words.
        - partly: the draft is right but misses or adds something that matters a little.
        - different: the draft says something staff did not, contradicts staff, or would mislead the customer.
        - correct_handover: the draft handed over and staff had to handle it themselves (an order problem, a decision, information the assistant could not have).
        - should_have_handed_over: the draft replied, but the case needed a person (an order problem, a refund or resend, an angry customer), or the draft promised or invented something.

        A draft that handed over where staff simply answered a common question is "different". Answer with JSON: grade, and a one-sentence note in English.
        PROMPT;
    }
}
