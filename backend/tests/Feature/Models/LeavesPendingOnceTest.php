<?php

namespace Tests\Feature\Models;

use App\Models\MembershipCheckoutAttempt;
use App\Models\MembershipPlan;
use App\Models\Reseller;
use App\Models\WalletTopupAttempt;
use App\Services\Membership\MembershipCheckoutAttemptStatus;
use App\Services\Reseller\WalletTopupAttemptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item 63 (2026-10-04): a checkout attempt leaves Pending exactly once.
 * A Failed/Expired answer read before a Paid one committed used to
 * overwrite it — the attempt showed Failed with the wallet already
 * credited, and a redelivered Paid then hit the ledger's dedupe index.
 */
class LeavesPendingOnceTest extends TestCase
{
    use RefreshDatabase;

    private function topup(): WalletTopupAttempt
    {
        $reseller = Reseller::query()->create(['business_name' => 'R', 'is_active' => true]);

        return WalletTopupAttempt::query()->create([
            'reseller_id' => $reseller->id,
            'reference' => 'WT-'.uniqid(),
            'amount_sen' => 5000,
            'total_charged_sen' => 5100,
            'channel_code' => 'fpx',
            'status' => WalletTopupAttemptStatus::Pending->value,
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    public function test_a_stale_failed_answer_never_overwrites_a_paid_topup(): void
    {
        $stale = $this->topup();
        WalletTopupAttempt::query()->whereKey($stale->id)->update(['status' => WalletTopupAttemptStatus::Paid->value]);

        $this->assertFalse($stale->leavePending(WalletTopupAttemptStatus::Failed));
        $this->assertSame(WalletTopupAttemptStatus::Paid, $stale->fresh()->status);
    }

    public function test_a_pending_topup_leaves_pending(): void
    {
        $attempt = $this->topup();

        $this->assertTrue($attempt->leavePending(WalletTopupAttemptStatus::Expired));
        $this->assertSame(WalletTopupAttemptStatus::Expired, $attempt->status);
        $this->assertSame(WalletTopupAttemptStatus::Expired, $attempt->fresh()->status);
    }

    public function test_a_stale_failed_answer_never_overwrites_a_paid_membership_attempt(): void
    {
        $stale = MembershipCheckoutAttempt::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'm@example.com',
            'membership_plan_id' => MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail()->id,
            'fee_sen' => 1990,
            'total_charged_sen' => 2200,
            'channel_code' => 'fpx',
            'subscription_number' => 'MS-'.uniqid(),
            'idempotency_key' => uniqid(),
            'status' => MembershipCheckoutAttemptStatus::Pending->value,
        ]);
        MembershipCheckoutAttempt::query()->whereKey($stale->id)->update(['status' => MembershipCheckoutAttemptStatus::Paid->value]);

        $this->assertFalse($stale->leavePending(MembershipCheckoutAttemptStatus::Failed));
        $this->assertSame(MembershipCheckoutAttemptStatus::Paid, $stale->fresh()->status);
    }
}
