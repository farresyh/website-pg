<?php

namespace Tests\Feature\Services\Llm;

use App\Services\Llm\ChatModelException;
use App\Services\Llm\ChatModels;
use App\Services\Llm\ChatRequest;
use App\Services\Llm\ClaudeChatModel;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * ADR-136 decision 12 (run #3): the Claude adapter behind ChatModel. The SDK
 * talks through a PSR-18 transport, not Laravel's Http, so these tests hand it
 * a Guzzle mock and read what it sent.
 */
class ClaudeChatModelTest extends TestCase
{
    /** @var list<array{request: RequestInterface}> */
    private array $sent = [];

    private function model(array $responses): ClaudeChatModel
    {
        $stack = HandlerStack::create(new MockHandler(array_map(
            fn (array|int $body) => is_int($body)
                ? new Response($body, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'boom']]))
                : new Response(200, ['Content-Type' => 'application/json'], json_encode($body)),
            $responses,
        )));
        $stack->push(Middleware::history($this->sent));

        return new ClaudeChatModel('claude-haiku-5-5', 'secret-key', transporter: new Guzzle(['handler' => $stack]));
    }

    private function message(array $content, string $stop = 'end_turn'): array
    {
        return [
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
            'content' => $content, 'stop_reason' => $stop, 'stop_sequence' => null,
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
        ];
    }

    private function body(int $i): array
    {
        return json_decode((string) $this->sent[$i]['request']->getBody(), true);
    }

    public function test_it_sends_the_key_in_a_header_with_tools_schema_and_low_effort(): void
    {
        $model = $this->model([$this->message([
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'game_info', 'input' => ['game' => 'Mobile Legends']],
        ], 'tool_use')]);

        $response = $model->complete(new ChatRequest('sys', [['role' => 'user', 'text' => 'hi']],
            [['name' => 'game_info', 'description' => 'd', 'parameters' => ['type' => 'object']]],
            ['type' => 'object', 'properties' => ['action' => ['type' => 'string']]]));

        $this->assertNull($response->text);
        $this->assertSame([['name' => 'game_info', 'args' => ['game' => 'Mobile Legends']]], $response->toolCalls);
        $this->assertSame([100, 20], [$response->inputTokens, $response->outputTokens]);

        $request = $this->sent[0]['request'];
        $this->assertSame(['Bearer secret-key'], $request->getHeader('Authorization'));
        $this->assertStringNotContainsString('secret-key', (string) $request->getUri());
        $body = $this->body(0);
        $this->assertSame('claude-haiku-5-5', $body['model']);
        $this->assertSame('sys', $body['system']);
        $this->assertSame('game_info', $body['tools'][0]['name']);
        $this->assertSame(['type' => 'object'], $body['tools'][0]['input_schema']);
        $this->assertSame('low', $body['output_config']['effort']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertFalse($body['output_config']['format']['schema']['additionalProperties']);
    }

    public function test_tool_results_go_back_in_one_user_message_matched_to_their_ids(): void
    {
        $model = $this->model([
            $this->message([
                ['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'game_info', 'input' => ['game' => 'Free Fire']],
                ['type' => 'tool_use', 'id' => 'toolu_b', 'name' => 'game_info', 'input' => ['game' => 'PUBG Mobile']],
            ], 'tool_use'),
            $this->message([['type' => 'text', 'text' => '{"action":"reply"}']]),
        ]);

        $request = new ChatRequest('sys', [['role' => 'user', 'text' => 'hi']]);
        $first = $model->complete($request);
        $second = $model->complete($request->with([
            ['role' => 'model', 'parts' => $first->parts],
            ['role' => 'tool', 'name' => 'game_info', 'result' => ['link' => 'ff']],
            ['role' => 'tool', 'name' => 'game_info', 'result' => ['link' => 'pubg']],
        ]));

        $this->assertSame('{"action":"reply"}', $second->text);
        $messages = $this->body(1)['messages'];
        $this->assertCount(3, $messages);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame(['toolu_a', 'toolu_b'], array_column($messages[1]['content'], 'id'));
        $this->assertSame('user', $messages[2]['role']);
        $this->assertSame(['toolu_a', 'toolu_b'], array_column($messages[2]['content'], 'tool_use_id'));
        $this->assertSame('{"link":"ff"}', $messages[2]['content'][0]['content']);
    }

    public function test_a_refusal_or_an_api_error_is_a_chat_model_exception(): void
    {
        $refused = $this->model([$this->message([], 'refusal')]);
        try {
            $refused->complete(new ChatRequest('sys', [['role' => 'user', 'text' => 'hi']]));
            $this->fail('A refusal must throw.');
        } catch (ChatModelException $e) {
            $this->assertStringContainsString('declined', $e->getMessage());
        }

        $this->expectException(ChatModelException::class);
        // 400 is not retried, so one queued response is enough.
        $this->model([400])->complete(new ChatRequest('sys', [['role' => 'user', 'text' => 'hi']]));
    }

    public function test_the_run_needs_a_key_for_every_provider_in_the_candidates(): void
    {
        config(['services.gemini.api_key' => 'g', 'services.anthropic.api_key' => null]);
        $this->assertSame(['ANTHROPIC_API_KEY'], app(ChatModels::class)->missingKeys());
        $this->assertFalse(app(ChatModels::class)->configured());

        config(['services.anthropic.api_key' => 'a']);
        $this->assertTrue(app(ChatModels::class)->configured());
        $this->assertInstanceOf(ClaudeChatModel::class, app(ChatModels::class)->make('claude-haiku-5-5'));
    }

    public function test_haiku_is_priced_from_config(): void
    {
        // 1,000 in at $0.10/M + 1,000 out at $0.50/M = $0.0006 = 600 micros.
        $this->assertSame(600, app(ChatModels::class)->costMicros('claude-haiku-5-5', 1000, 1000));
    }
}
