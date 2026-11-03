<?php

namespace App\Services\Llm;

/**
 * ADR-136 decision 33: the provider-neutral seam every customer-support model
 * call goes through. One adapter per provider (GeminiChatModel today) and a
 * FakeChatModel for tests. The Report Assistant's GeminiClient is separate.
 */
interface ChatModel
{
    public function complete(ChatRequest $request): ChatResponse;
}
