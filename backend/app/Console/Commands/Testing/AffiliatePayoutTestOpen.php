<?php

namespace App\Console\Commands\Testing;

use App\Models\Affiliate;
use App\Services\Affiliate\AffiliateWithdrawalService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Test-only helper: invoked as a genuinely separate OS process by
 * tests/Concurrency/AffiliateWithdrawalConcurrencyTest.php so two payout
 * runs race for real against the same affiliate's own `ledger_accounts`
 * mutex row (separate PHP process + separate DB connection each) — same
 * shape as ResellerWalletTopupTestInitiate.
 */
#[Signature('app:affiliate-payout-test-open {affiliateId} {resultFile}')]
#[Description('Test-only: run the monthly payout for one affiliate and write the outcome to a result file.')]
class AffiliatePayoutTestOpen extends Command
{
    public function handle(AffiliateWithdrawalService $service): int
    {
        $affiliate = Affiliate::query()->findOrFail((int) $this->argument('affiliateId'));
        $withdrawal = $service->openMonthly($affiliate);

        file_put_contents($this->argument('resultFile'), $withdrawal === null ? 'skipped' : 'opened:'.$withdrawal->id);

        return self::SUCCESS;
    }
}
