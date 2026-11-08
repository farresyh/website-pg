<?php

namespace App\Console\Commands\Reseller;

use App\Services\Rebate\RebateMonthService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** ADR-139 decisions 9, 10, 12, 19: see RebateMonthService. Daily, so a missed run catches up. */
#[Signature('app:expire-rebate-rewards')]
#[Description('Expire unclaimed reseller rebate rewards past their claim deadline.')]
class ExpireRebateRewardsCommand extends Command
{
    public function handle(RebateMonthService $months): int
    {
        $this->info($months->expireDue().' reward(s) expired.');

        return self::SUCCESS;
    }
}
