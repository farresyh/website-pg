<?php

namespace App\Services\ReportAssistant\Gemini;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adapter for Gemini's `generateContent` REST API
 * (generativelanguage.googleapis.com/v1beta), Flash tier (ADR-087
 * decision 4). Auth is `?key=` query-param (Gemini's documented scheme
 * for this endpoint — no bearer-header option), the request body is
 * `{contents: [{role, parts: [{text}]}], systemInstruction, generationConfig}`,
 * and the reply text is `candidates[0].content.parts[0].text`. Same
 * short-timeout discipline as ChipGateway/PlunkMailer — never left to
 * hang on an admin-facing chat request.
 */
final class GeminiApiClient implements GeminiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds = 30,
        private readonly int $connectTimeoutSeconds = 5,
    ) {}

    public function generate(string $systemPrompt, array $turns, ?string $responseMimeType = null): string
    {
        $body = [
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => array_map(
                fn (array $turn) => ['role' => $turn['role'], 'parts' => [['text' => $turn['text']]]],
                $turns,
            ),
        ];

        if ($responseMimeType !== null) {
            $body['generationConfig'] = ['responseMimeType' => $responseMimeType];
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->post("{$this->baseUrl}/models/{$this->model}:generateContent?key={$this->apiKey}", $body);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not reach Gemini: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException('Gemini API error ('.$response->status().'): '.$response->body());
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($text)) {
            throw new RuntimeException('Gemini returned an unexpected response shape.');
        }

        return $text;
    }
}
