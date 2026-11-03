<?php

namespace App\Services\Llm;

use InvalidArgumentException;

/**
 * Resolves a configured model id (`services.cs_assistant.models`) to its
 * adapter. Tests bind their own instance with `fake()`.
 */
class ChatModels
{
    /** @var array<string, ChatModel> */
    private array $fakes = [];

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys((array) config('services.cs_assistant.models'));
    }

    public function make(string $id): ChatModel
    {
        if (isset($this->fakes[$id])) {
            return $this->fakes[$id];
        }

        $model = $this->price($id) ?? throw new InvalidArgumentException("Unknown chat model {$id}.");

        return match ($model['provider']) {
            'gemini' => new GeminiChatModel(
                model: $id,
                apiKey: (string) config('services.gemini.api_key'),
                baseUrl: (string) config('services.gemini.base_url'),
                timeoutSeconds: (int) config('services.cs_assistant.timeout', 30),
            ),
        };
    }

    public function configured(): bool
    {
        return filled(config('services.gemini.api_key'));
    }

    /** US dollars, in millionths, for one call. */
    public function costMicros(string $id, int $inputTokens, int $outputTokens): int
    {
        $price = $this->price($id);

        return (int) round($inputTokens * ($price['input_usd_per_m'] ?? 0) + $outputTokens * ($price['output_usd_per_m'] ?? 0));
    }

    /** Model ids contain dots, so the config is read as a whole (dot notation would split them). */
    private function price(string $id): ?array
    {
        return ((array) config('services.cs_assistant.models'))[$id] ?? null;
    }

    public function fake(string $id, ChatModel $model): void
    {
        $this->fakes[$id] = $model;
    }
}
