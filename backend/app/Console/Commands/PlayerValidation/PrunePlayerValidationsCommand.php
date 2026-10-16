<?php

namespace App\Console\Commands\PlayerValidation;

use App\Models\PlayerValidation;
use App\Models\PublicPlayerCheck;
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
 *
 * ADR-123 decision 8: the public Game ID Checker's `public_player_checks`
 * carry the same nickname/ID data, so they follow the same window.
 */
#[Signature('app:prune-player-validations')]
#[Description('Delete player_validations and public_player_checks rows older than the configured retention window.')]
class PrunePlayerValidationsCommand extends Command
{
    public function handle(): int
    {
        $retentionDays = (int) config('services.player_validation.retention_days');

        $deleted = PlayerValidation::query()
            ->where('validated_at', '<=', now()->subDays($retentionDays))
            ->delete();

        $deletedChecks = PublicPlayerCheck::query()
            ->where('created_at', '<=', now()->subDays($retentionDays))
            ->delete();

        $this->info("Pruned {$deleted} player_validations row(s) and {$deletedChecks} public_player_checks row(s) older than {$retentionDays} day(s).");
        Log::info('Pruned player_validations rows', ['deleted' => $deleted, 'deleted_public_checks' => $deletedChecks, 'retention_days' => $retentionDays]);

        return self::SUCCESS;
    }
}
