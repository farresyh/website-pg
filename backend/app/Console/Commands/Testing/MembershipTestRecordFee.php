<?php

namespace App\Console\Commands\Testing;

use App\Services\Membership\MembershipFeeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Test-only helper, same pattern as MembershipQuotaTestDecrement: invoked
 * as a genuinely separate OS process so two fee records with the SAME
 * idempotency key race for real. ADR-027 continued addendum decision 15
 * / Phase 6.5 (Q11) — the double-submit serialization point is the
 * fee-records unique index, proven here rather than assumed.
 */
#[Signature('app:membership-test-record-fee {resellerId} {email} {planId} {amount} {adminId} {idempotencyKey} {resultFile}')]
#[Description('Test-only: record a membership fee and write the outcome to a result file.')]
class MembershipTestRecordFee extends Command
{
    public function handle(MembershipFeeService $fees): int
    {
        $resellerId = (int) $this->argument('resellerId');
        $email = $this->argument('email');
        $planId = (int) $this->argument('planId');
        $amount = (int) $this->argument('amount');
        $adminId = (int) $this->argument('adminId');
        $idempotencyKey = $this->argument('idempotencyKey');
        $resultFile = $this->argument('resultFile');

        try {
            $membership = $fees->recordFeePaid($resellerId, $email, $planId, $amount, $adminId, null, $idempotencyKey);

            file_put_contents($resultFile, (string) $membership->id);
        } catch (\Throwable $e) {
            file_put_contents($resultFile, 'error: '.get_class($e).': '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
