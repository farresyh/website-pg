<?php

namespace App\Jobs;

use App\Models\CsKnowledgeSuggestion;
use App\Services\CsAssistant\Shadow\CsSuggestionWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** ADR-136 PR 3b: one nightly model call, on the `cs-assistant` lane (decision 33). */
class WriteCsSuggestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public int $suggestionId)
    {
        $this->onQueue('cs-assistant');
    }

    public function handle(CsSuggestionWriter $service): void
    {
        $row = CsKnowledgeSuggestion::query()->find($this->suggestionId);
        if ($row !== null) {
            $service->write($row);
        }
    }
}
