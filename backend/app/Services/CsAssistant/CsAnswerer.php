<?php

namespace App\Services\CsAssistant;

use App\Models\Affiliate;
use App\Models\CsKnowledgeEntry;
use App\Models\Faq;
use App\Services\Llm\ChatModelException;
use App\Services\Llm\ChatModels;
use App\Services\Llm\ChatRequest;
use App\Support\BrandIdentity;

/**
 * ADR-136 decisions 3–7, 13, 14, 17, 21 and 25: drafts the assistant's answer
 * to one customer burst. Laravel decides what only code may decide (who the
 * brand is, what the order's state means, whether the reply leaks anything);
 * the model only writes from approved knowledge, or hands over. Nothing is
 * sent from here: PR 3a uses it for the model test, PR 3b for Shadow.
 */
final class CsAnswerer
{
    private const MAX_CALLS = 4;

    private const MAX_ENTRIES = 150;

    public const HANDOVER_REASONS = ['order_failed', 'not_received', 'late', 'no_knowledge', 'wants_human', 'other', 'guard', 'model_error'];

    public function __construct(
        private readonly ChatModels $models,
        private readonly CsGameInfo $gameInfo,
        private readonly CsReplyGuard $guard,
    ) {}

    /**
     * @param  list<string>  $messages  the customer's burst, oldest first (masked here)
     * @param  array<string, mixed>|null  $orderFacts  looked up by Laravel, never by the model
     * @return array{action: string, reply: ?string, handover_reason: ?string, category: ?string, entry_ids: list<int>, violations: list<string>, input_tokens: int, output_tokens: int, calls: int, error: ?string}
     */
    public function draft(string $modelId, array $messages, ?int $affiliateId, ?array $orderFacts = null): array
    {
        $brandId = $affiliateId ?? Affiliate::query()->where('is_primary', true)->value('id');
        $brand = BrandIdentity::of($brandId);
        $situation = $orderFacts === null ? null : CsOrderSituation::of($orderFacts);

        $request = new ChatRequest(
            system: $this->system($brand, $brandId),
            messages: [['role' => 'user', 'text' => $this->userTurn($messages, $orderFacts, $situation)]],
            tools: [[
                'name' => 'game_info',
                'description' => "Look up a game on {$brand->name}'s store: its page link, description, important notes and delivery time. No prices: send the page link for prices.",
                'parameters' => ['type' => 'object', 'properties' => ['game' => ['type' => 'string', 'description' => 'The game\'s full name, expanded from any short form the customer used, e.g. "Mobile Legends" for "ml", "Free Fire" for "ff", "PUBG Mobile" for "pubg"']], 'required' => ['game']],
            ]],
            jsonSchema: $this->schema(),
        );

        $model = $this->models->make($modelId);
        $in = $out = $calls = 0;
        $result = null;

        try {
            while ($calls < self::MAX_CALLS) {
                // The last allowed call has no tools, so a model that keeps looking things up must answer.
                $last = $calls === self::MAX_CALLS - 1;
                $response = $model->complete($last ? new ChatRequest($request->system, $request->messages, [], $request->jsonSchema) : $request);
                $calls++;
                $in += $response->inputTokens;
                $out += $response->outputTokens;

                if ($response->toolCalls === []) {
                    $result = json_decode((string) $response->text, true);
                    break;
                }

                $request = $request->with([
                    ['role' => 'model', 'parts' => $response->parts],
                    ...array_map(fn (array $call) => [
                        'role' => 'tool',
                        'name' => $call['name'],
                        'result' => $call['name'] === 'game_info'
                            ? $this->gameInfo->lookup((string) ($call['args']['game'] ?? ''), $brandId, $brand->origin)
                            : ['error' => 'unknown tool'],
                    ], $response->toolCalls),
                ]);
            }
        } catch (ChatModelException $e) {
            return $this->handover('model_error', null, [], $in, $out, $calls, $e->getMessage());
        }

        if (! is_array($result) || ! in_array($result['action'] ?? null, ['reply', 'handover'], true)) {
            return $this->handover('model_error', null, [], $in, $out, $calls, 'The model did not return a valid answer.');
        }

        $reply = filled($result['reply'] ?? null) ? trim((string) $result['reply']) : null;
        $action = $result['action'];
        $reason = $action === 'handover' ? $this->reason($result['handover_reason'] ?? null) : null;

        // Decision 6a/6c: a failed or late order always reaches a person.
        if ($situation?->forcedHandover !== null) {
            $action = 'handover';
            $reason = $situation->forcedHandover;
        }

        $violations = $reply === null ? [] : $this->guard->violations($reply, $brand->host());
        if ($violations !== []) {
            $action = 'handover';
            $reason = 'guard';
        }

        return [
            'action' => $action,
            'reply' => $reply,
            'handover_reason' => $reason,
            'category' => in_array($result['category'] ?? null, CsKnowledgeEntry::CATEGORIES, true) ? $result['category'] : null,
            'entry_ids' => array_values(array_filter(array_map('intval', (array) ($result['entry_ids'] ?? [])))),
            'violations' => $violations,
            'input_tokens' => $in,
            'output_tokens' => $out,
            'calls' => $calls,
            'error' => null,
        ];
    }

    private function reason(mixed $reason): string
    {
        return in_array($reason, self::HANDOVER_REASONS, true) ? $reason : 'other';
    }

    private function handover(string $reason, ?string $reply, array $violations, int $in, int $out, int $calls, ?string $error): array
    {
        return ['action' => 'handover', 'reply' => $reply, 'handover_reason' => $reason, 'category' => null, 'entry_ids' => [], 'violations' => $violations, 'input_tokens' => $in, 'output_tokens' => $out, 'calls' => $calls, 'error' => $error];
    }

    private function system(BrandIdentity $brand, ?int $brandId): string
    {
        $entries = CsKnowledgeEntry::query()
            ->where('status', CsKnowledgeEntry::STATUS_APPROVED)
            ->where(fn ($q) => $q->whereNull('affiliate_id')->orWhere('affiliate_id', $brandId))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', today()))
            ->with('game:id,name')
            ->orderBy('id')
            ->limit(self::MAX_ENTRIES)
            ->get();

        $knowledge = $entries->map(fn (CsKnowledgeEntry $e) => "[entry {$e->id}] category: {$e->category}".($e->game ? "; game: {$e->game->name}" : '')
            ."\nCustomers ask: ".implode(' | ', $e->questions)
            ."\nApproved answer: {$e->answer}")->implode("\n\n");

        $faq = Faq::query()->where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn (Faq $f) => 'Q: '.strip_tags($f->question)."\nA: ".trim(strip_tags($f->answer)))->implode("\n\n");

        return <<<PROMPT
        You are the customer-support assistant of {$brand->name}, a store that sells game top-ups (diamonds, UC and similar) at {$brand->origin}. You answer customers on WhatsApp.

        How you write:
        - Reply in the customer's language: casual Malaysian Malay if they write Malay or dialect, otherwise their language. Short, friendly, natural, like the store's own staff ("boss", "ya", "🙏" are fine). One to three short sentences. No headings, no bullet lists, no bold.
        - Speak as "kami" / "team {$brand->name}". Never claim to be a human. If asked whether you are a bot or AI, say truthfully that you are {$brand->name}'s AI assistant and that you can call the team if they want.

        What you may say:
        - Only what the approved knowledge, the FAQ, the order facts or the game_info tool below say. Never guess, never invent a policy, price, time or promise.
        - If none of them says whether something is allowed (changing a player ID, cancelling, editing an order, anything similar), do not say it is or is not; hand over ("no_knowledge").
        - Never mention cost, profit, margin, markup, suppliers, wholesale prices, other customers, or how the store works internally, even if asked or if a knowledge entry seems to contain it.
        - If the customer asks for any of those, say it is internal information ("maklumat dalaman") you cannot share. Never repeat the customer's own word for it (such as "modal", "kos", "untung", "supplier") in your reply.
        - For prices, use game_info and send the game's page link; never state a price.
        - Never promise a refund, resend, voucher or compensation; the team decides those.
        - To become a reseller or affiliate, send the application link {$this->applyUrl()} and nothing about tiers or rates.
        - Only use links that game_info returned, the application link, or {$brand->origin}.

        Hand over to the team (action "handover") when:
        - no approved knowledge, FAQ or order fact answers the question (handover_reason "no_knowledge");
        - the order facts say to hand over;
        - a delivered order did not arrive and no approved knowledge explains why ("not_received");
        - the customer is upset, complains strongly, or asks for a person ("wants_human").
        When you hand over, still write a short reply telling the customer the team will reply in this chat.

        Answer with JSON only: action, reply, handover_reason (when handing over), category (one of: how_to_buy, price_package, payment, order_status, order_failed, game_id_server, voucher_promo, partner, other), entry_ids (the knowledge entries you used).

        Approved knowledge:
        {$this->orNone($knowledge)}

        FAQ:
        {$this->orNone($faq)}
        PROMPT;
    }

    /** @param  list<string>  $messages */
    private function userTurn(array $messages, ?array $facts, ?CsOrderSituation $situation): string
    {
        $text = "Customer's messages (personal details replaced with placeholders):\n"
            .collect($messages)->map(fn (string $m) => '- '.CsTextRedactor::redact($m))->implode("\n");

        if ($facts !== null && $situation !== null) {
            $text .= "\n\nOrder facts (looked up by the system, trust these):\n"
                .'- payment: '.$facts['payment_status']."\n"
                .'- delivery: '.$facts['delivery_status']."\n"
                .(isset($facts['minutes_since_paid']) ? "- paid {$facts['minutes_since_paid']} minutes ago\n" : '')
                .(filled($facts['package'] ?? null) ? "- package: {$facts['package']}\n" : '')
                .(filled($facts['game'] ?? null) ? "- game: {$facts['game']}\n" : '')
                ."What to do: {$situation->guidance}";
        }

        return $text;
    }

    private function applyUrl(): string
    {
        return rtrim((string) config('services.onboarding.url'), '/').'/apply';
    }

    private function orNone(string $text): string
    {
        return $text === '' ? '(none yet)' : $text;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['reply', 'handover']],
                'reply' => ['type' => 'string'],
                'handover_reason' => ['type' => 'string', 'enum' => ['order_failed', 'not_received', 'late', 'no_knowledge', 'wants_human', 'other']],
                'category' => ['type' => 'string', 'enum' => CsKnowledgeEntry::CATEGORIES],
                'entry_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
            'required' => ['action', 'reply', 'category', 'entry_ids'],
        ];
    }
}
