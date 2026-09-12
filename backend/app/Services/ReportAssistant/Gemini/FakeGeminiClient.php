<?php

namespace App\Services\ReportAssistant\Gemini;

/**
 * Zero-network fake bound in `testing`/`e2e` (mirrors FakePaymentGateway/
 * FakeSupplierAdapter's own reasoning: a text-to-SQL model call can't be
 * asserted against in an automated test, and must never make a real,
 * billed API call from the test suite). Responses are a plain FIFO
 * queue — the first `generate()` call gets the planning-turn reply
 * (JSON), the second gets the final-answer-turn reply (prose), and so
 * on for a multi-turn test.
 */
final class FakeGeminiClient implements GeminiClient
{
    /** @var array<int, string> */
    private array $queue;

    /** @var array<int, array{systemPrompt: string, turns: array, responseMimeType: ?string}> */
    public array $calls = [];

    /**
     * @param  array<int, string>  $responses
     */
    public function __construct(array $responses = [])
    {
        $this->queue = $responses;
    }

    public function generate(string $systemPrompt, array $turns, ?string $responseMimeType = null): string
    {
        $this->calls[] = compact('systemPrompt', 'turns', 'responseMimeType');

        if ($this->queue === []) {
            return $responseMimeType === 'application/json'
                ? '{"needs_query":false,"direct_answer":"Fake Gemini has no more canned responses queued."}'
                : 'Fake Gemini has no more canned responses queued.';
        }

        return array_shift($this->queue);
    }
}
