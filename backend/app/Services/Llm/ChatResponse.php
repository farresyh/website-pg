<?php

namespace App\Services\Llm;

final readonly class ChatResponse
{
    /**
     * @param  list<array{name: string, args: array<string, mixed>}>  $toolCalls
     * @param  array<int, mixed>  $parts  the provider's raw reply, for replay in the next turn
     */
    public function __construct(
        public ?string $text,
        public array $toolCalls,
        public int $inputTokens,
        public int $outputTokens,
        public array $parts = [],
    ) {}
}
