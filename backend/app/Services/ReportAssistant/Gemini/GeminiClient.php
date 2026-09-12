<?php

namespace App\Services\ReportAssistant\Gemini;

/**
 * ADR-087 decision 4 — the one seam ReportAssistantService talks to.
 * Deliberately thin, same "isolate the cost of swapping providers"
 * instinct as PaymentGateway: this project has already had one
 * founder-directed gateway swap (Xendit → CHIP), and the ADR itself
 * frames "Gemini, not Anthropic" as a cost/reasoning call that could
 * change again.
 */
interface GeminiClient
{
    /**
     * @param  array<int, array{role: 'user'|'model', text: string}>  $turns  Prior conversation turns, oldest first, ending with the current user turn.
     * @param  string|null  $responseMimeType  Pass 'application/json' to force a structured JSON reply (the planning turn); null for free-form prose (the final answer turn).
     */
    public function generate(string $systemPrompt, array $turns, ?string $responseMimeType = null): string;
}
