<?php

namespace App\Console\Commands\Testing;

use App\Models\ResellerSubscription;
use App\Services\Reseller\ResellerSubscriptionStatus;
use App\Services\Reseller\ResellerTierFeeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Test-only helper: invoked as a genuinely separate OS process by
 * tests/Concurrency/ResellerTierFeeConcurrencyTest.php so two tier-fee
 * charges race for real (separate PHP process + separate DB connection
 * each), proving the subscription lock + ledger_accounts lock serialize
 * them — only one debit lands, the other goes to grace.
 */
#[Signature('app:reseller-test-charge-tier-fee {subscriptionId} {resultFile}')]
#[Description('Test-only: run one ResellerTierFeeService::chargeCycle() and write the resulting status to a file.')]
class ResellerTestChargeTierFee extends Command
{
    public function handle(ResellerTierFeeService $service): int
    {
        $subscription = ResellerSubscription::query()->findOrFail((int) $this->argument('subscriptionId'));

        $result = $service->chargeCycle($subscription);

        file_put_contents(
            $this->argument('resultFile'),
            $result->status instanceof ResellerSubscriptionStatus ? $result->status->value : (string) $result->status,
        );

        return self::SUCCESS;
    }
}
