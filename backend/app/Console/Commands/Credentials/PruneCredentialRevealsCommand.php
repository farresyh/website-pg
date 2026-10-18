<?php

namespace App\Console\Commands\Credentials;

use App\Models\CredentialReveal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR-124 decision 11 — credential_reveals (who saw or replaced a
 * customer's login) is kept one year, the same pattern as
 * PruneReportAssistantAuditLogsCommand.
 */
#[Signature('app:prune-credential-reveals')]
#[Description('Delete credential_reveals rows older than the one-year retention window.')]
class PruneCredentialRevealsCommand extends Command
{
    private const RETENTION_DAYS = 365;

    public function handle(): int
    {
        $deleted = CredentialReveal::query()
            ->where('created_at', '<=', now()->subDays(self::RETENTION_DAYS))
            ->delete();

        $this->info("Pruned {$deleted} credential_reveals row(s) older than ".self::RETENTION_DAYS.' day(s).');
        Log::info('Pruned credential_reveals rows', ['deleted' => $deleted]);

        return self::SUCCESS;
    }
}
