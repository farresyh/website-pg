<?php

namespace App\Console\Commands\Reseller;

use App\Models\ResellerBotCommandLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * PR-F build addendum decision 2 — `reseller_bot_command_logs` retention
 * (`services.openwa.command_log_retention_days`, default 7, matching
 * `player_validations`' own PII-adjacent window). Same inert-until-real-
 * cron pattern as PrunePlayerValidationsCommand.
 */
#[Signature('app:prune-reseller-bot-command-logs')]
#[Description('Delete reseller_bot_command_logs rows older than the configured retention window.')]
class PruneResellerBotCommandLogsCommand extends Command
{
    public function handle(): int
    {
        $retentionDays = (int) config('services.openwa.command_log_retention_days');

        $deleted = ResellerBotCommandLog::query()
            ->where('created_at', '<=', now()->subDays($retentionDays))
            ->delete();

        $this->info("Pruned {$deleted} reseller_bot_command_logs row(s) older than {$retentionDays} day(s).");
        Log::info('Pruned reseller_bot_command_logs rows', ['deleted' => $deleted, 'retention_days' => $retentionDays]);

        return self::SUCCESS;
    }
}
