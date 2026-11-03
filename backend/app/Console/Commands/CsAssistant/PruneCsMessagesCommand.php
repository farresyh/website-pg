<?php

namespace App\Console\Commands\CsAssistant;

use App\Models\CsKnowledgeSuggestion;
use App\Models\CsMessage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR-136 decision 30 (PR 1 build notes, point 2): masked CS messages and the
 * question-and-answer suggestions made from them are kept two years. Approved
 * knowledge entries are not customer data and stay until archived.
 */
#[Signature('cs:prune-messages')]
#[Description('Delete masked CS messages and knowledge suggestions older than two years.')]
class PruneCsMessagesCommand extends Command
{
    public const RETENTION_YEARS = 2;

    public function handle(): int
    {
        $cutoff = now()->subYears(self::RETENTION_YEARS);

        $messages = CsMessage::query()->where('sent_at', '<', $cutoff)->delete();
        $suggestions = CsKnowledgeSuggestion::query()->where('asked_at', '<', $cutoff)->delete();

        $this->info("Pruned {$messages} CS message(s) and {$suggestions} suggestion(s) older than ".self::RETENTION_YEARS.' years.');
        Log::info('Pruned CS messages', ['messages' => $messages, 'suggestions' => $suggestions]);

        return self::SUCCESS;
    }
}
