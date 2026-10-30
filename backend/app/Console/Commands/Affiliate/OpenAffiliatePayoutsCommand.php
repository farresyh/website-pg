<?php

namespace App\Console\Commands\Affiliate;

use App\Services\Affiliate\AffiliateWithdrawalService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR-132: opens this Malaysia month's payout ticket for every affiliate that
 * qualifies. Scheduled daily (00:10 MYT), not only on the 1st: the
 * `payout_period` unique index makes a repeat a no-op, so a missed 1st is
 * repaired the next day. Exits non-zero if any affiliate failed so the
 * scheduler/health check sees it.
 */
#[Signature('app:open-affiliate-payouts')]
#[Description('Open this month\'s payout withdrawal ticket for every affiliate with a payable balance (ADR-132).')]
class OpenAffiliatePayoutsCommand extends Command
{
    public function handle(AffiliateWithdrawalService $service): int
    {
        $result = $service->openAll();

        $this->info("Affiliate payouts: {$result['opened']} opened, {$result['failed']} failed.");
        Log::info('Opened affiliate payouts', $result);

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
