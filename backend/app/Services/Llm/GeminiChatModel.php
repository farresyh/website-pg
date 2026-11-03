<?php

namespace App\Services\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * ADR-136 decision 12: Gemini's `generateContent` behind ChatModel. The key
 * goes in the `x-goog-api-key` header, never the URL, so it stays out of logs.
 * The model's own parts (including thought signatures) are replayed unchanged
 * on the next turn, which Gemini requires for function calling.
 */
final class GeminiChatModel implements ChatModel
{
    public function __construct(
        private readonly string $model,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 30,
        private readonly string $thinkingLevel = 'low',
    ) {}

    public function complete(ChatRequest $request): ChatResponse
    {
        $body = [
            'systemInstruction' => ['parts' => [['text' => $request->system]]],
            'contents' => array_map($this->content(...), $request->messages),
            'generationConfig' => ['thinkingConfig' => ['thinkingLevel' => $this->thinkingLevel]],
        ];

        if ($request->tools !== []) {
            $body['tools'] = [['functionDeclarations' => array_map(fn (array $t) => [
                'name' => $t['name'],
                'description' => $t['description'],
                'parametersJsonSchema' => $t['parameters'],
            ], $request->tools)]];
        }

        if ($request->jsonSchema !== null) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
            $body['generationConfig']['responseJsonSchema'] = $request->jsonSchema;
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->connectTimeout(5)
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->post("{$this->baseUrl}/models/{$this->model}:generateContent", $body);
        } catch (ConnectionException $e) {
            throw new ChatModelException('Could not reach Gemini: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new ChatModelException('Gemini error '.$response->status().': '.mb_substr($response->body(), 0, 500));
        }

        $parts = $response->json('candidates.0.content.parts');
        if (! is_array($parts)) {
            throw new ChatModelException('Gemini returned no content (finish reason: '.($response->json('candidates.0.finishReason') ?? $response->json('promptFeedback.blockReason') ?? 'unknown').').');
        }

        $text = null;
        $calls = [];
        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                $calls[] = ['name' => (string) $part['functionCall']['name'], 'args' => (array) ($part['functionCall']['args'] ?? [])];
            } elseif (isset($part['text']) && ! ($part['thought'] ?? false)) {
                $text = ($text ?? '').$part['text'];
            }
        }

        return new ChatResponse(
            text: $text,
            toolCalls: $calls,
            inputTokens: (int) $response->json('usageMetadata.promptTokenCount', 0),
            // Thinking is billed as output.
            outputTokens: (int) $response->json('usageMetadata.candidatesTokenCount', 0) + (int) $response->json('usageMetadata.thoughtsTokenCount', 0),
            parts: $parts,
        );
    }

    /** @param  array<string, mixed>  $m */
    private function content(array $m): array
    {
        return match ($m['role']) {
            'user' => ['role' => 'user', 'parts' => [['text' => $m['text']]]],
            'model' => ['role' => 'model', 'parts' => $m['parts']],
            'tool' => ['role' => 'user', 'parts' => [['functionResponse' => ['name' => $m['name'], 'response' => $m['result']]]]],
        };
    }
}
