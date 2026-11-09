<?php

namespace App\Console\Commands\Reseller;

use App\Services\Rebate\RebateMonthService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** ADR-139 decisions 9, 10, 12, 19: see RebateMonthService. Daily, so a missed run catches up. */
#[Signature('app:finalize-rebate-months')]
#[Description('Finalise every ended Malaysia month of the reseller rebate (write unclaimed reached rewards and adjustments).')]
class FinalizeRebateMonthsCommand extends Command
{
    public function handle(RebateMonthService $months): int
    {
        $this->info($months->finalizeDue().' month(s) finalised.');

        return self::SUCCESS;
    }
}
