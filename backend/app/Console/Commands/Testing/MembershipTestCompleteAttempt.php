<?php

namespace App\Console\Commands\Testing;

use App\Models\Membership;
use App\Models\MembershipCheckoutAttempt;
use App\Services\Membership\MembershipSubscriptionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Test-only helper for MembershipCheckoutWebhookReconcileConcurrencyTest
 * (ADR-068 S14) — invoked as a separate OS process so the webhook path
 * and the reconcile path racing to complete the SAME
 * membership_checkout_attempts row is a genuine race, not a simulated
 * one.
 */
#[Signature('app:membership-test-complete-attempt {subscriptionNumber} {resultFile}')]
#[Description('Test-only: complete a paid membership checkout attempt and write the resulting membership id to a file.')]
class MembershipTestCompleteAttempt extends Command
{
    public function handle(MembershipSubscriptionService $subscriptions): int
    {
        $attempt = MembershipCheckoutAttempt::query()
            ->where('subscription_number', $this->argument('subscriptionNumber'))
            ->firstOrFail();
        $resultFile = $this->argument('resultFile');

        try {
            $subscriptions->completePaidAttempt($attempt);

            $membershipId = Membership::query()
                ->where('reseller_id', $attempt->reseller_id)
                ->where('email', $attempt->email)
                ->value('id');

            file_put_contents($resultFile, (string) $membershipId);
        } catch (\Throwable $e) {
            file_put_contents($resultFile, 'error: '.get_class($e).': '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
