<?php

namespace App\Console\Commands\Reseller;

use App\Services\Reseller\ResellerTierChangeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * ADR-139 decision 7: applies every wallet tier change whose 1st (KL) has
 * come. Hourly, so 16:00 UTC is 00:00 KL on the dot and a missed run
 * catches up an hour later; applying twice is a no-op.
 */
#[Signature('app:apply-reseller-tier-changes')]
#[Description('Apply reseller wallet tier changes that take effect today (Malaysia time).')]
class ApplyResellerTierChangesCommand extends Command
{
    public function handle(ResellerTierChangeService $tierChanges): int
    {
        $applied = $tierChanges->applyDue();
        $this->info("Applied {$applied} tier change(s).");

        return self::SUCCESS;
    }
}
