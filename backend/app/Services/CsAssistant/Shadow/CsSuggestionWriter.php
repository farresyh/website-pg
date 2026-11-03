<?php

namespace App\Services\CsAssistant\Shadow;

use App\Models\CsAssistantSettings;
use App\Models\CsKnowledgeEntry;
use App\Models\CsKnowledgeSuggestion;
use App\Services\Llm\ChatModelException;
use App\Services\Llm\ChatModels;
use App\Services\Llm\ChatRequest;

/**
 * ADR-136 decision 10 (PR 3b): the model rewrites each pending suggestion for
 * the admin: the question generalised, the staff answer without internal
 * facts or one customer's details, a category, and the approved entry it
 * already belongs to, if any ("update entry X"). The admin still approves,
 * edits or rejects; nothing reaches the assistant without that.
 */
final class CsSuggestionWriter
{
    public function __construct(
        private readonly ChatModels $models,
        private readonly CsSpend $spend,
    ) {}

    public function write(CsKnowledgeSuggestion $suggestion): void
    {
        if ($suggestion->ai_written_at !== null || $suggestion->status !== CsKnowledgeSuggestion::STATUS_PENDING) {
            return;
        }

        $model = CsAssistantSettings::current()->judge_model;
        if (! $this->models->has($model) || ! $this->models->configured([$model]) || ! $this->spend->allow()) {
            return;
        }

        $entries = CsKnowledgeEntry::query()->where('status', CsKnowledgeEntry::STATUS_APPROVED)->orderBy('id')->get();
        $list = $entries->map(fn (CsKnowledgeEntry $e) => "[{$e->id}] {$e->category}: ".implode(' | ', $e->questions))->implode("\n");

        try {
            $response = $this->models->make($model)->complete(new ChatRequest(
                system: $this->system($list === '' ? '(none yet)' : $list),
                messages: [['role' => 'user', 'text' => "Customer asked:\n{$suggestion->question}\n\nStaff answered:\n{$suggestion->answer}"]],
                jsonSchema: [
                    'type' => 'object',
                    'properties' => [
                        'question' => ['type' => 'string'],
                        'answer' => ['type' => 'string'],
                        'category' => ['type' => 'string', 'enum' => CsKnowledgeEntry::CATEGORIES],
                        'entry_id' => ['type' => 'integer'],
                    ],
                    'required' => ['question', 'answer', 'category', 'entry_id'],
                ],
            ));
        } catch (ChatModelException) {
            return; // tried again tomorrow night
        }

        $result = json_decode((string) $response->text, true);
        if (! is_array($result) || blank($result['question'] ?? null) || blank($result['answer'] ?? null)) {
            return;
        }

        $entryId = (int) ($result['entry_id'] ?? 0);

        $suggestion->update([
            'ai_question' => mb_substr(trim((string) $result['question']), 0, 1000),
            'ai_answer' => mb_substr(trim((string) $result['answer']), 0, 4000),
            'ai_category' => in_array($result['category'] ?? null, CsKnowledgeEntry::CATEGORIES, true) ? $result['category'] : null,
            'ai_entry_id' => $entries->contains('id', $entryId) ? $entryId : null,
            'ai_cost_usd_micros' => $this->models->costMicros($model, $response->inputTokens, $response->outputTokens),
            'ai_written_at' => now(),
        ]);
    }

    private function system(string $entries): string
    {
        return <<<PROMPT
        You help the owner of a game top-up store in Malaysia turn a real WhatsApp exchange between a customer and staff into a reusable support answer. The owner reviews everything you write.

        Write:
        - question: the customer's question as a short, general question any customer could ask, in casual Malaysian Malay. No names, numbers, order numbers or details of this one order.
        - answer: staff's answer, rewritten so it is true for any customer asking that question, in staff's own casual tone ("boss", "ya", "🙏"). Remove cost, profit, margin, supplier names, wholesale prices, other customers, internal operations and anything only true for this one order. Keep it short. If staff's answer only fits this one order, write the general part, or the honest "the team will check" if there is none.
        - category: the closest one.
        - entry_id: the id of the approved entry below that already answers this question (the owner may merge into it), or 0 if none does.

        Approved entries:
        {$entries}
        PROMPT;
    }
}
