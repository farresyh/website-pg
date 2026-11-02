<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\ChipSettledTransaction;
use App\Models\MembershipCheckoutAttempt;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\PaymentSettlement;
use App\Models\PlatformSettings;
use App\Models\Reseller;
use App\Models\WalletTopupAttempt;
use App\Services\Accounting\ChipTransactionMatchType;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Membership\MembershipCheckoutAttemptStatus;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use App\Services\Reseller\ResellerWalletService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-135 decisions 12 and 13: a payment CHIP settled whose record here is
 * not paid is shown live on the settlement screen, and Re-check with CHIP
 * fixes it through that record's own paid seam. Never a manual credit.
 */
class SettlementRecheckTest extends TestCase
{
    use RefreshDatabase;

    /** CHIP's answer: 'paid' at $chipAmountSen, 'cancelled', or 'down'. */
    public string $chipSays = 'paid';

    public ?int $chipAmountSen = null;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $test = $this;
        $this->app->bind('payment-gateway.chip', fn () => new class($test) implements PaymentGateway
        {
            public function __construct(private readonly SettlementRecheckTest $test) {}

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                throw new RuntimeException('not used');
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                return match ($this->test->chipSays) {
                    'down' => PaymentResponse::failure('503', 'CHIP down'),
                    'cancelled' => PaymentResponse::success(['status' => 'cancelled', 'failure_is_final' => true], status: PaymentStatus::Failed),
                    default => PaymentResponse::success(['status' => 'paid', 'amount_sen' => $this->test->chipAmountSen, 'fee_sen' => 60], status: PaymentStatus::Paid),
                };
            }

            public function cancelPayment(string $paymentRequestId): PaymentResponse
            {
                throw new RuntimeException('not used');
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                throw new RuntimeException('not used');
            }

            public function parseWebhookEvent(array $payload): PaymentWebhookEvent
            {
                throw new RuntimeException('not used');
            }
        });
    }

    private function settlement(): PaymentSettlement
    {
        return PaymentSettlement::query()->create([
            'date_from' => '2026-10-20', 'date_to' => '2026-10-20',
            'matched_gross_sen' => 0, 'matched_fee_sen' => 0, 'matched_net_sen' => 0,
            'file_gross_sen' => 0, 'file_fee_sen' => 0, 'file_net_sen' => 0,
            'status' => 'variance', 'original_filename' => 'settlement.xlsx',
        ]);
    }

    private function settled(PaymentSettlement $settlement, string $transactionId, ?ChipTransactionMatchType $type, ?int $id, int $amountSen): ChipSettledTransaction
    {
        return ChipSettledTransaction::query()->create([
            'transaction_id' => $transactionId,
            'matched_type' => $type?->value,
            'matched_id' => $id,
            'local_gross_sen' => $type === null ? null : $amountSen,
            'amount_sen' => $amountSen,
            'fee_sen' => 60,
            'net_amount_sen' => $amountSen - 60,
            'acquirer' => 'FPX',
            'settled_on' => '2026-10-20',
            'payment_settlement_id' => $settlement->id,
        ]);
    }

    private function topup(string $status = 'failed'): WalletTopupAttempt
    {
        $reseller = Reseller::query()->create(['business_name' => 'Recheck Reseller', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        return WalletTopupAttempt::query()->create([
            'reseller_id' => $reseller->id,
            'reference' => 'WT-RECHECK001',
            'amount_sen' => 10000,
            'total_charged_sen' => 10100,
            'channel_code' => 'fpx',
            'status' => $status,
            'chip_payment_ref' => 'chip-topup-1',
            'expires_at' => now()->subHour(),
        ]);
    }

    private function asSuperAdmin(): void
    {
        $this->actingAsSteppedUp(AdminUser::factory()->create(['role' => 'super_admin']));
    }

    private function recheck(ChipSettledTransaction $transaction): TestResponse
    {
        return $this->postJson("/api/accounting/settlements/transactions/{$transaction->id}/recheck");
    }

    // --- decision 12: shown live, never stored ---

    public function test_show_lists_settled_transactions_whose_record_is_not_paid_here(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
        $settlement = $this->settlement();
        $topup = $this->topup();
        $paidOrder = Order::factory()->create(['payment_gateway' => 'chip', 'payment_ref' => 'chip-order-ok', 'payment_status' => PaymentStatus::Paid, 'final_amount' => 1100]);

        $unpaid = $this->settled($settlement, 'chip-topup-1', ChipTransactionMatchType::WalletTopupAttempt, $topup->id, 10100);
        $this->settled($settlement, 'chip-order-ok', ChipTransactionMatchType::Order, $paidOrder->id, 1100);
        $this->settled($settlement, 'chip-unknown', null, null, 500);

        $this->getJson("/api/accounting/settlements/{$settlement->id}")
            ->assertOk()
            ->assertJsonPath('settled_but_unpaid', [$unpaid->id]);

        // Fixed by any path (here the webhook's seam): the list follows, the settlement row is untouched.
        app(ResellerWalletService::class)->applyPaid($topup, 10100, null);

        $this->getJson("/api/accounting/settlements/{$settlement->id}")->assertJsonPath('settled_but_unpaid', []);
        $this->assertSame('variance', $settlement->fresh()->status);
    }

    // --- decision 13: Re-check routes to the record's own paid seam ---

    public function test_recheck_credits_a_top_up_chip_settled(): void
    {
        $this->asSuperAdmin();
        $topup = $this->topup();
        $this->chipAmountSen = 10100;
        $transaction = $this->settled($this->settlement(), 'chip-topup-1', ChipTransactionMatchType::WalletTopupAttempt, $topup->id, 10100);

        $this->recheck($transaction)->assertOk()->assertJsonPath('outcome', 'applied');
        $this->recheck($transaction)->assertOk()->assertJsonPath('outcome', 'already_paid');

        $this->assertSame(WalletTopupAttemptStatus::Paid, $topup->fresh()->status);
        $this->assertSame(10000, app(ResellerWalletService::class)->balance($topup->reseller));
    }

    /** ADR-131 decision 6: paid money is never refused, but the founder must refund it. */
    public function test_recheck_on_a_closed_reseller_says_to_refund_the_payer(): void
    {
        $this->asSuperAdmin();
        $topup = $this->topup();
        $topup->reseller->delete();
        $this->chipAmountSen = 10100;
        $transaction = $this->settled($this->settlement(), 'chip-topup-1', ChipTransactionMatchType::WalletTopupAttempt, $topup->id, 10100);

        $this->recheck($transaction)
            ->assertOk()
            ->assertJsonPath('outcome', 'applied_to_closed_account')
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'refund the payer'));
    }

    public function test_recheck_activates_a_membership_chip_settled(): void
    {
        $this->asSuperAdmin();
        PlatformSettings::current()->update(['membership_enabled' => true]);
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        $attempt = MembershipCheckoutAttempt::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'recheck@example.com',
            'membership_plan_id' => $plan->id,
            'fee_sen' => $plan->fee_sen,
            'total_charged_sen' => $plan->fee_sen + 100,
            'channel_code' => 'fpx',
            'subscription_number' => 'MS-RECHECK01',
            'idempotency_key' => 'idem-recheck',
            'payment_ref' => 'chip-ms-1',
            'status' => MembershipCheckoutAttemptStatus::Expired->value,
        ]);
        $this->chipAmountSen = $attempt->total_charged_sen;
        $transaction = $this->settled($this->settlement(), 'chip-ms-1', ChipTransactionMatchType::MembershipCheckoutAttempt, $attempt->id, $attempt->total_charged_sen);

        $this->recheck($transaction)->assertOk()->assertJsonPath('outcome', 'applied');
        $this->assertSame(MembershipCheckoutAttemptStatus::Paid, $attempt->fresh()->status);
    }

    /** A failed order paid late is recorded but never auto-delivered (M-4). */
    public function test_recheck_sends_a_failed_order_chip_settled_to_needs_review(): void
    {
        $this->asSuperAdmin();
        $order = Order::factory()->create([
            'payment_gateway' => 'chip', 'payment_ref' => 'chip-order-1',
            'payment_status' => PaymentStatus::Failed, 'final_amount' => 1100, 'is_test' => false,
        ]);
        $this->chipAmountSen = 1100;
        $transaction = $this->settled($this->settlement(), 'chip-order-1', ChipTransactionMatchType::Order, $order->id, 1100);

        $this->recheck($transaction)->assertOk()->assertJsonPath('outcome', 'flagged_for_review');

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(DeliveryStatus::NeedsReview, $order->fresh()->delivery_status);
    }

    public function test_recheck_never_credits_a_different_amount(): void
    {
        $this->asSuperAdmin();
        $topup = $this->topup();
        $this->chipAmountSen = 9999;
        $transaction = $this->settled($this->settlement(), 'chip-topup-1', ChipTransactionMatchType::WalletTopupAttempt, $topup->id, 10100);

        $this->recheck($transaction)->assertOk()->assertJsonPath('outcome', 'amount_mismatch');
        $this->assertSame(WalletTopupAttemptStatus::Failed, $topup->fresh()->status);
    }

    public function test_recheck_changes_nothing_when_chip_says_not_paid(): void
    {
        $this->asSuperAdmin();
        $topup = $this->topup();
        $this->chipSays = 'cancelled';
        $transaction = $this->settled($this->settlement(), 'chip-topup-1', ChipTransactionMatchType::WalletTopupAttempt, $topup->id, 10100);

        $this->recheck($transaction)->assertOk()->assertJsonPath('outcome', 'not_paid_at_chip');
        $this->assertSame(WalletTopupAttemptStatus::Failed, $topup->fresh()->status);
    }

    public function test_recheck_reports_chip_unreachable(): void
    {
        $this->asSuperAdmin();
        $topup = $this->topup();
        $this->chipSays = 'down';
        $transaction = $this->settled($this->settlement(), 'chip-topup-1', ChipTransactionMatchType::WalletTopupAttempt, $topup->id, 10100);

        $this->recheck($transaction)->assertStatus(502)->assertJsonPath('outcome', 'chip_unreachable');
    }

    public function test_recheck_refuses_an_unmatched_transaction(): void
    {
        $this->asSuperAdmin();
        $transaction = $this->settled($this->settlement(), 'chip-unknown', null, null, 500);

        $this->recheck($transaction)->assertStatus(422);
    }

    public function test_recheck_needs_a_super_admin_who_stepped_up(): void
    {
        $topup = $this->topup();
        $transaction = $this->settled($this->settlement(), 'chip-topup-1', ChipTransactionMatchType::WalletTopupAttempt, $topup->id, 10100);

        $admin = AdminUser::factory()->create(['role' => 'super_admin', 'mfa_required' => true, 'mfa_enabled' => true]);
        Sanctum::actingAs($admin);
        $this->withHeader('Referer', 'http://localhost:3000/');
        $this->recheck($transaction)->assertForbidden();

        $this->actingAsSteppedUp(AdminUser::factory()->create(['role' => 'admin']));
        $this->recheck($transaction)->assertForbidden();

        $this->assertSame(WalletTopupAttemptStatus::Failed, $topup->fresh()->status);
    }
}
