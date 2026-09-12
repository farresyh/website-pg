<?php

namespace App\Console\Commands\ReportAssistant;

use App\Models\ReportAssistantAuditLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR-087 decision 8 — the assistant's audit log (question + generated
 * SQL + result sample, independent of the ephemeral chat session) is
 * retained 90 days. Runs on a schedule (see routes/console.php), same
 * inert-until-real-cron pattern as PrunePlayerValidationsCommand.
 */
#[Signature('app:prune-report-assistant-audit-logs')]
#[Description('Delete report_assistant_audit_logs rows older than the 90-day retention window.')]
class PruneReportAssistantAuditLogsCommand extends Command
{
    private const RETENTION_DAYS = 90;

    public function handle(): int
    {
        $deleted = ReportAssistantAuditLog::query()
            ->where('created_at', '<=', now()->subDays(self::RETENTION_DAYS))
            ->delete();

        $this->info("Pruned {$deleted} report_assistant_audit_logs row(s) older than ".self::RETENTION_DAYS.' day(s).');
        Log::info('Pruned report_assistant_audit_logs rows', ['deleted' => $deleted]);

        return self::SUCCESS;
    }
}
