<?php

namespace App\Console\Commands\CsAssistant;

use App\Jobs\WriteCsSuggestionJob;
use App\Models\CsKnowledgeSuggestion;
use App\Services\CsAssistant\CsKnowledgeSuggester;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR-136 decision 10: nightly, finished CS conversations become pending
 * knowledge suggestions for an admin to review (PR 2), each then rewritten by
 * the model (PR 3b). Re-runnable.
 */
#[Signature('cs:suggest-knowledge')]
#[Description('Propose CS knowledge entries from finished customer-support conversations.')]
class SuggestCsKnowledgeCommand extends Command
{
    public function handle(CsKnowledgeSuggester $suggester): int
    {
        $added = $suggester->run(CarbonImmutable::now());

        // PR 3b: the model writes a reviewable version of each pending one, on the cs-assistant lane.
        $queued = 0;
        CsKnowledgeSuggestion::query()->where('status', CsKnowledgeSuggestion::STATUS_PENDING)->whereNull('ai_written_at')
            ->pluck('id')->each(function (int $id) use (&$queued) {
                WriteCsSuggestionJob::dispatch($id);
                $queued++;
            });

        $this->info("Added {$added} CS knowledge suggestion(s); {$queued} queued for the model to write.");
        Log::info('CS knowledge suggestions added', ['added' => $added, 'queued_for_writing' => $queued]);

        return self::SUCCESS;
    }
}
