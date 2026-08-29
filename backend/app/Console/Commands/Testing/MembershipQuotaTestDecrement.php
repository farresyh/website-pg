<?php

namespace App\Console\Commands\Testing;

use App\Services\Membership\MembershipQuotaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Test-only helper, same pattern as VoucherTestRedeem/LedgerTestWithdraw:
 * invoked as a genuinely separate OS process so two decrements race for
 * real. ADR-027 Phase 6: two distinct order IDs, same membership — the
 * real "two browser tabs, same member" scenario the row lock must
 * serialize.
 */
#[Signature('app:membership-test-quota-decrement {membershipId} {orderId} {amount} {resultFile}')]
#[Description('Test-only: attempt a single membership quota decrement and write the outcome to a result file.')]
class MembershipQuotaTestDecrement extends Command
{
    public function handle(MembershipQuotaService $quota): int
    {
        $membershipId = (int) $this->argument('membershipId');
        $orderId = (int) $this->argument('orderId');
        $amount = (int) $this->argument('amount');
        $resultFile = $this->argument('resultFile');

        $succeeded = $quota->decrement($membershipId, $orderId, $amount);

        file_put_contents($resultFile, $succeeded ? 'success' : 'failed');

        return self::SUCCESS;
    }
}
