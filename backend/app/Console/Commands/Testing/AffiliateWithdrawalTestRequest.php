<?php

namespace App\Console\Commands\Testing;

use App\Models\Affiliate;
use App\Services\Affiliate\AffiliateWithdrawalService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Test-only helper: invoked as a genuinely separate OS process by
 * tests/Concurrency/AffiliateWithdrawalConcurrencyTest.php so two
 * withdrawal requests race for real against the same affiliate's own
 * `ledger_accounts` mutex row (separate PHP process + separate DB
 * connection each) — same shape as ResellerWalletTopupTestInitiate.
 */
#[Signature('app:affiliate-withdrawal-test-request {affiliateId} {amountSen} {resultFile}')]
#[Description('Test-only: attempt a single affiliate withdrawal request and write the outcome to a result file.')]
class AffiliateWithdrawalTestRequest extends Command
{
    public function handle(AffiliateWithdrawalService $service): int
    {
        $affiliate = Affiliate::query()->findOrFail((int) $this->argument('affiliateId'));
        $resultFile = $this->argument('resultFile');

        try {
            $withdrawal = $service->request($affiliate, [
                'amount' => (int) $this->argument('amountSen'),
                'bank_name' => 'Maybank',
                'bank_account_no' => '1234567',
                'bank_account_holder' => 'Race Test Affiliate',
            ], null);

            file_put_contents($resultFile, 'success:'.$withdrawal->id);
        } catch (ValidationException $e) {
            file_put_contents($resultFile, 'failed:'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
