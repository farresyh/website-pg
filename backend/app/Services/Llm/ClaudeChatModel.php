<?php

namespace App\Services\Llm;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Messages\ToolUseBlock;
use GuzzleHttp\Client as Guzzle;
use Psr\Http\Client\ClientInterface;

/**
 * ADR-136 decision 12: Claude's Messages API behind ChatModel, through the
 * official SDK. The assistant's own content blocks (thinking included) are
 * replayed unchanged on the next turn, and each tool result is matched to its
 * `tool_use` id in call order, since ChatRequest's tool turns carry only a name.
 */
final class ClaudeChatModel implements ChatModel
{
    private readonly Client $client;

    public function __construct(
        private readonly string $model,
        string $apiKey,
        int $timeoutSeconds = 30,
        private readonly string $effort = 'low',
        ?ClientInterface $transporter = null,
    ) {
        // The SDK leaves the timeout to the transport, so it is set on Guzzle here.
        $this->client = new Client(apiKey: $apiKey, requestOptions: [
            'transporter' => $transporter ?? new Guzzle(['timeout' => $timeoutSeconds, 'connect_timeout' => 5]),
            'maxRetries' => 1,
        ]);
    }

    public function complete(ChatRequest $request): ChatResponse
    {
        $outputConfig = ['effort' => $this->effort];
        if ($request->jsonSchema !== null) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => [...$request->jsonSchema, 'additionalProperties' => false]];
        }

        $params = [
            'model' => $this->model,
            'maxTokens' => 8000,
            'system' => $request->system,
            'messages' => $this->messages($request->messages),
            'outputConfig' => $outputConfig,
        ];
        if ($request->tools !== []) {
            $params['tools'] = array_map(fn (array $t) => [
                'name' => $t['name'],
                'description' => $t['description'],
                'inputSchema' => $t['parameters'],
            ], $request->tools);
        }

        try {
            $message = $this->client->messages->create(...$params);
        } catch (APIException $e) {
            throw new ChatModelException('Claude error: '.mb_substr($e->getMessage(), 0, 500), previous: $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new ChatModelException('Claude declined ('.($message->stopDetails?->category ?? 'no category').').');
        }

        $text = null;
        $calls = [];
        foreach ($message->content as $block) {
            if ($block instanceof ToolUseBlock) {
                $calls[] = ['name' => $block->name, 'args' => (array) $block->input];
            } elseif ($block->type === 'text') {
                $text = ($text ?? '').$block->text;
            }
        }

        return new ChatResponse(
            text: $text,
            toolCalls: $calls,
            inputTokens: (int) $message->usage->inputTokens,
            // Thinking is billed as output and already counted in outputTokens.
            outputTokens: (int) $message->usage->outputTokens,
            parts: $message->content,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function messages(array $messages): array
    {
        $out = [];
        $pendingIds = [];

        foreach ($messages as $m) {
            if ($m['role'] === 'model') {
                $out[] = ['role' => 'assistant', 'content' => $m['parts']];
                $pendingIds = [];
                foreach ($m['parts'] as $block) {
                    if ($block instanceof ToolUseBlock) {
                        $pendingIds[] = [$block->name, $block->id];
                    }
                }

                continue;
            }

            if ($m['role'] === 'tool') {
                $result = [
                    'type' => 'tool_result',
                    'toolUseID' => $this->takeId($pendingIds, (string) $m['name']),
                    'content' => json_encode($m['result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
                // All results for one assistant turn go back in a single user message.
                $last = array_key_last($out);
                if ($last !== null && $out[$last]['role'] === 'user' && is_array($out[$last]['content'])) {
                    $out[$last]['content'][] = $result;
                } else {
                    $out[] = ['role' => 'user', 'content' => [$result]];
                }

                continue;
            }

            $out[] = ['role' => 'user', 'content' => (string) $m['text']];
        }

        return $out;
    }

    /** @param  list<array{0: string, 1: string}>  $pending */
    private function takeId(array &$pending, string $name): string
    {
        foreach ($pending as $i => [$toolName, $id]) {
            if ($toolName === $name) {
                array_splice($pending, $i, 1);

                return $id;
            }
        }

        throw new ChatModelException("No tool_use block for the {$name} result.");
    }
}
