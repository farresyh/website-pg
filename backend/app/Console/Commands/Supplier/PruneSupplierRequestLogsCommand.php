<?php

namespace App\Console\Commands\Supplier;

use App\Models\SupplierRequestLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR-051 decision 7 — two windows, not one: every call_type prunes at
 * services.supplier_request_log.retention_days (default 30), except
 * `validatePlayer` rows, which follow
 * services.supplier_request_log.validate_player_retention_days
 * (default 7) to match player_validations' own PII-retention window
 * (ADR-021) — same category of data, same discipline. Mirrors
 * PrunePlayerValidationsCommand's shape rather than extending it
 * (different table, different owning concern).
 */
#[Signature('app:prune-supplier-request-logs')]
#[Description('Delete supplier_request_logs rows older than their configured retention window.')]
class PruneSupplierRequestLogsCommand extends Command
{
    public function handle(): int
    {
        $defaultDays = (int) config('services.supplier_request_log.retention_days');
        $validatePlayerDays = (int) config('services.supplier_request_log.validate_player_retention_days');

        $deletedDefault = SupplierRequestLog::query()
            ->where('call_type', '!=', 'validatePlayer')
            ->where('created_at', '<=', now()->subDays($defaultDays))
            ->delete();

        $deletedValidatePlayer = SupplierRequestLog::query()
            ->where('call_type', 'validatePlayer')
            ->where('created_at', '<=', now()->subDays($validatePlayerDays))
            ->delete();

        $total = $deletedDefault + $deletedValidatePlayer;

        $this->info("Pruned {$total} supplier_request_logs row(s) — {$deletedDefault} at {$defaultDays}d, {$deletedValidatePlayer} validatePlayer at {$validatePlayerDays}d.");
        Log::info('Pruned supplier_request_logs rows', [
            'deleted_default' => $deletedDefault,
            'deleted_validate_player' => $deletedValidatePlayer,
            'default_retention_days' => $defaultDays,
            'validate_player_retention_days' => $validatePlayerDays,
        ]);

        return self::SUCCESS;
    }
}
