<?php

namespace App\Console\Commands\Testing;

use App\Models\AffiliateSubscription;
use App\Services\Affiliate\AffiliateSubscriptionStatus;
use App\Services\Affiliate\AffiliateTierFeeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Test-only helper: invoked as a genuinely separate OS process by
 * tests/Concurrency/AffiliateTierFeeConcurrencyTest.php so two tier-fee
 * charges race for real (separate PHP process + separate DB connection
 * each), proving the subscription lock + ledger_accounts lock serialize
 * them — only one debit lands, the other goes to grace.
 */
#[Signature('app:affiliate-test-charge-tier-fee {subscriptionId} {resultFile}')]
#[Description('Test-only: run one AffiliateTierFeeService::chargeCycle() and write the resulting status to a file.')]
class AffiliateTestChargeTierFee extends Command
{
    public function handle(AffiliateTierFeeService $service): int
    {
        $subscription = AffiliateSubscription::query()->findOrFail((int) $this->argument('subscriptionId'));

        $result = $service->chargeCycle($subscription);

        file_put_contents(
            $this->argument('resultFile'),
            $result->status instanceof AffiliateSubscriptionStatus ? $result->status->value : (string) $result->status,
        );

        return self::SUCCESS;
    }
}
