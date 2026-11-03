<?php

namespace App\Console\Commands\CsAssistant;

use App\Services\CsAssistant\CsKnowledgeSuggester;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR-136 decision 10 (PR 2, no AI): nightly, finished CS conversations
 * become pending knowledge suggestions for an admin to review. Re-runnable.
 */
#[Signature('cs:suggest-knowledge')]
#[Description('Propose CS knowledge entries from finished customer-support conversations.')]
class SuggestCsKnowledgeCommand extends Command
{
    public function handle(CsKnowledgeSuggester $suggester): int
    {
        $added = $suggester->run(CarbonImmutable::now());

        $this->info("Added {$added} CS knowledge suggestion(s).");
        Log::info('CS knowledge suggestions added', ['added' => $added]);

        return self::SUCCESS;
    }
}
