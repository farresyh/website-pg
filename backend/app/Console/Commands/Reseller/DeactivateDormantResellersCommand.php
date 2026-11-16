<?php

namespace App\Console\Commands\Reseller;

use App\Services\Reseller\ResellerDormancyService;
use Illuminate\Console\Command;

/**
 * ADR-142 decision 23: daily at 10:00 MYT (02:00 UTC), so the warning and the
 * notice reach the reseller in the day. Does nothing while the switch in
 * Admin → Settings → Reseller inactivity is off.
 */
final class DeactivateDormantResellersCommand extends Command
{
    protected $signature = 'app:deactivate-dormant-resellers';

    protected $description = 'Warn, then deactivate, resellers with no transaction for the set number of days';

    public function handle(ResellerDormancyService $dormancy): int
    {
        $result = $dormancy->run();

        $this->info(sprintf('Reseller dormancy: %d warned, %d deactivated.', count($result['warned']), count($result['deactivated'])));

        return self::SUCCESS;
    }
}
