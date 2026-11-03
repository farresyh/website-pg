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
            'anthropic' => new ClaudeChatModel(
                model: $id,
                apiKey: (string) config('services.anthropic.api_key'),
                timeoutSeconds: (int) config('services.cs_assistant.timeout', 30),
            ),
        };
    }

    /** Every provider a configured model needs has its key set. */
    public function configured(): bool
    {
        return $this->missingKeys() === [];
    }

    /** @return list<string> the env names of the keys still unset */
    public function missingKeys(): array
    {
        $keys = ['gemini' => ['services.gemini.api_key', 'GEMINI_API_KEY'], 'anthropic' => ['services.anthropic.api_key', 'ANTHROPIC_API_KEY']];
        $providers = array_unique(array_column((array) config('services.cs_assistant.models'), 'provider'));

        return array_values(array_map(fn (string $p) => $keys[$p][1], array_filter($providers, fn (string $p) => blank(config($keys[$p][0])))));
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
