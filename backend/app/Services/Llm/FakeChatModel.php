<?php

namespace App\Services\Llm;

use RuntimeException;

/** Tests only: replies from a script, records every request. */
final class FakeChatModel implements ChatModel
{
    /** @var list<ChatRequest> */
    public array $requests = [];

    /** @param  list<ChatResponse|\Throwable>  $script */
    public function __construct(private array $script) {}

    public static function json(array $payload, int $in = 100, int $out = 20): ChatResponse
    {
        return new ChatResponse(json_encode($payload), [], $in, $out, [['text' => json_encode($payload)]]);
    }

    public static function call(string $name, array $args): ChatResponse
    {
        return new ChatResponse(null, [['name' => $name, 'args' => $args]], 50, 10, [['functionCall' => ['name' => $name, 'args' => $args]]]);
    }

    public function complete(ChatRequest $request): ChatResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->script) ?? throw new RuntimeException('FakeChatModel script is empty.');

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
