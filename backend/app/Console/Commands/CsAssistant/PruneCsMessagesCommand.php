<?php

namespace App\Console\Commands\CsAssistant;

use App\Models\CsBotDraft;
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
#[Description('Delete masked CS messages and suggestions older than two years, and Shadow drafts older than 90 days.')]
class PruneCsMessagesCommand extends Command
{
    public const RETENTION_YEARS = 2;

    public const DRAFT_RETENTION_DAYS = 90;

    public function handle(): int
    {
        $cutoff = now()->subYears(self::RETENTION_YEARS);

        $messages = CsMessage::query()->where('sent_at', '<', $cutoff)->delete();
        $suggestions = CsKnowledgeSuggestion::query()->where('asked_at', '<', $cutoff)->delete();
        // Decision 18: Shadow drafts follow the 90-day retention.
        $drafts = CsBotDraft::query()->where('created_at', '<', now()->subDays(self::DRAFT_RETENTION_DAYS))->delete();

        $this->info("Pruned {$messages} CS message(s) and {$suggestions} suggestion(s) older than ".self::RETENTION_YEARS." years, {$drafts} Shadow draft(s) older than ".self::DRAFT_RETENTION_DAYS.' days.');
        Log::info('Pruned CS messages', ['messages' => $messages, 'suggestions' => $suggestions, 'drafts' => $drafts]);

        return self::SUCCESS;
    }
}
