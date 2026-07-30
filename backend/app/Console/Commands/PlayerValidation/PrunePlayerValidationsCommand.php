<?php

namespace App\Console\Commands\PlayerValidation;

use App\Models\PlayerValidation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR-021 — a `player_validations` row is written on every storefront
 * "Validate Player ID" attempt (nickname, country, player/server ID), not
 * just completed orders, with no TTL/cleanup anywhere until now. Runs on a
 * schedule (see routes/console.php), same inert-until-real-cron pattern as
 * SyncSupplierPricesJob/ReconcilePendingPaymentsCommand.
 *
 * Prunes on `validated_at` — the semantically real timestamp
 * (CheckoutController::assertPlayerIdIsValidated() already keys its own
 * freshness window off this same column) — not `created_at`.
 */
#[Signature('app:prune-player-validations')]
#[Description('Delete player_validations rows older than the configured retention window.')]
class PrunePlayerValidationsCommand extends Command
{
    public function handle(): int
    {
        $retentionDays = (int) config('services.player_validation.retention_days');

        $deleted = PlayerValidation::query()
            ->where('validated_at', '<=', now()->subDays($retentionDays))
            ->delete();

        $this->info("Pruned {$deleted} player_validations row(s) older than {$retentionDays} day(s).");
        Log::info('Pruned player_validations rows', ['deleted' => $deleted, 'retention_days' => $retentionDays]);

        return self::SUCCESS;
    }
}
