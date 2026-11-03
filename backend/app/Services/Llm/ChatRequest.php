<?php

namespace App\Services\Llm;

/**
 * One model call. `messages` is the conversation so far, oldest first:
 *  - ['role' => 'user', 'text' => string]
 *  - ['role' => 'model', 'parts' => array]  the provider's own reply, replayed as-is
 *  - ['role' => 'tool', 'name' => string, 'result' => array]
 * `tools` are function declarations: ['name', 'description', 'parameters' (JSON schema)].
 * `jsonSchema`, when set, asks for a JSON reply matching it.
 */
final readonly class ChatRequest
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     * @param  array<string, mixed>|null  $jsonSchema
     */
    public function __construct(
        public string $system,
        public array $messages,
        public array $tools = [],
        public ?array $jsonSchema = null,
    ) {}

    /** @param  list<array<string, mixed>>  $more */
    public function with(array $more): self
    {
        return new self($this->system, [...$this->messages, ...$more], $this->tools, $this->jsonSchema);
    }
}
