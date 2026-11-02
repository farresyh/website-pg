<?php

namespace Tests\Feature\Services\Payment;

use App\Models\MembershipCheckoutAttempt;
use App\Models\MembershipPlan;
use App\Models\PlatformSettings;
use App\Models\Reseller;
use App\Models\WalletTopupAttempt;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Membership\MembershipCheckoutAttemptStatus;
use App\Services\Membership\MembershipSubscriptionService;
use App\Services\Payment\AttemptPaymentOutcome;
use App\Services\Reseller\ResellerWalletService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ADR-135 decision 11: one paid seam per attempt type, the amount check
 * inside it. The webhook, reconcile and (later) Re-check with CHIP all
 * call applyPaid(); none keeps its own copy of the check.
 */
class AttemptApplyPaidTest extends TestCase
{
    use RefreshDatabase;

    private function topup(string $status = 'pending'): WalletTopupAttempt
    {
        $reseller = Reseller::query()->create(['business_name' => 'Seam Reseller', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        return WalletTopupAttempt::query()->create([
            'reseller_id' => $reseller->id,
            'reference' => 'WT-SEAM'.random_int(1000, 9999),
            'amount_sen' => 5000,
            'total_charged_sen' => 5100,
            'channel_code' => 'fpx',
            'status' => $status,
            'chip_payment_ref' => 'chip-seam',
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    private function wallets(): ResellerWalletService
    {
        return app(ResellerWalletService::class);
    }

    public function test_a_top_up_paid_at_the_right_amount_is_credited_once(): void
    {
        $attempt = $this->topup();

        $this->assertSame(AttemptPaymentOutcome::Applied, $this->wallets()->applyPaid($attempt, 5100, 61));
        $this->assertSame(AttemptPaymentOutcome::AlreadyProcessed, $this->wallets()->applyPaid($attempt->fresh(), 5100, 61));

        $this->assertSame(WalletTopupAttemptStatus::Paid, $attempt->fresh()->status);
        $this->assertSame(61, $attempt->fresh()->gateway_fee_sen);
        $this->assertSame(5000, $this->wallets()->balance($attempt->reseller));
    }

    public function test_a_top_up_with_a_wrong_or_missing_amount_is_not_credited(): void
    {
        $attempt = $this->topup();

        $this->assertSame(AttemptPaymentOutcome::AmountMismatch, $this->wallets()->applyPaid($attempt, 5000, null));
        $this->assertSame(AttemptPaymentOutcome::AmountMismatch, $this->wallets()->applyPaid($attempt, null, null));

        $this->assertSame(WalletTopupAttemptStatus::Pending, $attempt->fresh()->status);
        $this->assertSame(0, $this->wallets()->balance($attempt->reseller));
    }

    /** A late payment on an attempt that already ended is still honoured (the webhook's existing rule). */
    public function test_a_top_up_paid_after_it_failed_is_still_credited(): void
    {
        $attempt = $this->topup(WalletTopupAttemptStatus::Failed->value);

        $this->assertSame(AttemptPaymentOutcome::Applied, $this->wallets()->applyPaid($attempt, 5100, null));
        $this->assertSame(5000, $this->wallets()->balance($attempt->reseller));
    }

    /** ADR-131 decision 6: paid money is never refused, but the caller must be able to say it is stranded. */
    public function test_a_top_up_credited_to_a_closed_reseller_says_so(): void
    {
        $attempt = $this->topup();
        $attempt->reseller->delete();

        $this->assertSame(AttemptPaymentOutcome::AppliedToClosedAccount, $this->wallets()->applyPaid($attempt->fresh(), 5100, null));
    }

    private function membershipAttempt(): MembershipCheckoutAttempt
    {
        $this->primaryAffiliate();
        PlatformSettings::current()->update(['membership_enabled' => true]);
        Queue::fake();
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();

        return MembershipCheckoutAttempt::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'seam@example.com',
            'membership_plan_id' => $plan->id,
            'fee_sen' => $plan->fee_sen,
            'total_charged_sen' => $plan->fee_sen + 100,
            'channel_code' => 'fpx',
            'subscription_number' => 'MS-SEAM0001',
            'idempotency_key' => 'idem-seam',
            'status' => MembershipCheckoutAttemptStatus::Pending->value,
        ]);
    }

    public function test_a_membership_paid_at_the_right_amount_is_activated_once(): void
    {
        $attempt = $this->membershipAttempt();
        $service = app(MembershipSubscriptionService::class);

        $this->assertSame(AttemptPaymentOutcome::Applied, $service->applyPaid($attempt, $attempt->total_charged_sen, 77));
        $this->assertSame(AttemptPaymentOutcome::AlreadyProcessed, $service->applyPaid($attempt->fresh(), $attempt->total_charged_sen, 77));

        $this->assertSame(MembershipCheckoutAttemptStatus::Paid, $attempt->fresh()->status);
        $this->assertSame(77, $attempt->fresh()->gateway_fee_sen);
        $this->assertDatabaseCount('membership_fee_records', 1);
    }

    public function test_a_membership_with_a_wrong_or_missing_amount_is_not_activated(): void
    {
        $attempt = $this->membershipAttempt();
        $service = app(MembershipSubscriptionService::class);

        $this->assertSame(AttemptPaymentOutcome::AmountMismatch, $service->applyPaid($attempt, $attempt->fee_sen, null));
        $this->assertSame(AttemptPaymentOutcome::AmountMismatch, $service->applyPaid($attempt, null, null));

        $this->assertSame(MembershipCheckoutAttemptStatus::Pending, $attempt->fresh()->status);
        $this->assertDatabaseCount('memberships', 0);
    }
}
