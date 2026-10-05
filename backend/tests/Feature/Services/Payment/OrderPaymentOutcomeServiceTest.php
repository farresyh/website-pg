<?php

namespace Tests\Feature\Services\Payment;

use App\Jobs\FulfillOrderJob;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\MembershipQuotaDebit;
use App\Models\Order;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\OrderPaymentOutcome;
use App\Services\Payment\OrderPaymentOutcomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Item 63 (2026-10-04): the one seam every gateway answer about an order
 * goes through — the CHIP webhook and PaymentReconciliationService (the
 * scheduled sweep + "Check from Gateway"). The matrix below is the
 * invariant: from any current state, a Paid or Failed answer lands in
 * exactly one place, and nothing ships goods for a voucher/quota that
 * was already given back.
 */
class OrderPaymentOutcomeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function service(): OrderPaymentOutcomeService
    {
        return $this->app->make(OrderPaymentOutcomeService::class);
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'PG-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_ref' => 'pr-'.uniqid(),
            'payment_status' => PaymentStatus::Pending->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ], $overrides));
    }

    /** A Pending order holding a reserved voucher and a quota debit. */
    private function reservedOrder(): array
    {
        $voucher = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'KRS-OUTCOME',
            'customer_email' => 'buyer@example.com',
            'amount' => 300,
            'remaining' => 0,
            'status' => 'exhausted',
            'reason' => 'test',
        ]);
        $membership = Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'buyer@example.com',
            'membership_plan_id' => MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail()->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 0,
            'expires_at' => now()->addDays(20),
        ]);
        $order = $this->order(['voucher_id' => $voucher->id, 'voucher_discount' => 300, 'membership_id' => $membership->id]);
        VoucherRedemption::query()->create(['voucher_id' => $voucher->id, 'order_id' => $order->id, 'amount' => 300, 'status' => 'reserved']);
        MembershipQuotaDebit::query()->create(['order_id' => $order->id, 'membership_id' => $membership->id, 'amount_sen' => 1000]);
        $membership->update(['quota_remaining_sen' => 0]);

        return [$order, $voucher, $membership];
    }

    public function test_paid_on_a_pending_order_marks_paid_and_dispatches_fulfillment(): void
    {
        $order = $this->order();

        $this->assertSame(OrderPaymentOutcome::Fulfilling, $this->service()->applyPaid($order, 1100));

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertNotNull($order->fresh()->paid_at);
        Queue::assertPushed(FulfillOrderJob::class, 1);
    }

    public function test_paid_on_a_paid_order_is_a_no_op(): void
    {
        $order = $this->order(['payment_status' => PaymentStatus::Paid->value, 'paid_at' => now()]);

        $this->assertSame(OrderPaymentOutcome::AlreadyProcessed, $this->service()->applyPaid($order, 1100));

        Queue::assertNotPushed(FulfillOrderJob::class);
    }

    public function test_paid_with_the_wrong_amount_changes_nothing(): void
    {
        $order = $this->order();

        $this->assertSame(OrderPaymentOutcome::AmountMismatch, $this->service()->applyPaid($order, 999));

        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        Queue::assertNotPushed(FulfillOrderJob::class);
    }

    public function test_paid_after_failed_is_flagged_for_review_never_fulfilled(): void
    {
        $order = $this->order(['payment_status' => PaymentStatus::Failed->value]);

        $this->assertSame(OrderPaymentOutcome::FlaggedForReview, $this->service()->applyPaid($order, 1100));

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::Paid, $fresh->payment_status);
        $this->assertSame(DeliveryStatus::NeedsReview, $fresh->delivery_status);
        Queue::assertNotPushed(FulfillOrderJob::class);
    }

    /** M-4: a voucher already issued for the order. */
    public function test_paid_on_an_already_compensated_order_is_flagged_for_review(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value]);
        Voucher::query()->create([
            'order_id' => $order->id,
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'KRS-ISSUED',
            'customer_email' => 'buyer@example.com',
            'amount' => 1000,
            'remaining' => 1000,
            'status' => 'active',
            'reason' => 'test',
        ]);

        $this->assertSame(OrderPaymentOutcome::FlaggedForReview, $this->service()->applyPaid($order, 1100));

        Queue::assertNotPushed(FulfillOrderJob::class);
    }

    public function test_failed_on_a_pending_order_gives_back_voucher_and_quota(): void
    {
        [$order, $voucher, $membership] = $this->reservedOrder();

        $this->assertSame(OrderPaymentOutcome::Failed, $this->service()->applyFailed($order));

        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
        $this->assertSame(300, $voucher->fresh()->remaining);
        $this->assertSame(1000, $membership->fresh()->quota_remaining_sen);
    }

    public function test_failed_on_a_paid_order_changes_nothing(): void
    {
        [$order, $voucher] = $this->reservedOrder();
        $order->update(['payment_status' => PaymentStatus::Paid->value, 'paid_at' => now()]);

        $this->assertSame(OrderPaymentOutcome::AlreadyProcessed, $this->service()->applyFailed($order));

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(0, $voucher->fresh()->remaining);
    }

    public function test_failed_on_a_failed_order_is_a_no_op(): void
    {
        $order = $this->order(['payment_status' => PaymentStatus::Failed->value]);

        $this->assertSame(OrderPaymentOutcome::AlreadyProcessed, $this->service()->applyFailed($order));
    }

    /**
     * The invariant across a stale read: the caller loaded the order
     * while Pending, a Failed answer committed (voucher given back), and
     * only then does the Paid answer apply. It must re-read under the
     * lock and flag, never fulfil at a discount nothing backs.
     */
    public function test_paid_from_a_stale_pending_read_still_sees_the_committed_failure(): void
    {
        [$order] = $this->reservedOrder();
        $stale = Order::query()->findOrFail($order->id);
        $this->service()->applyFailed($order);

        $this->assertSame(OrderPaymentOutcome::FlaggedForReview, $this->service()->applyPaid($stale, 1100));

        Queue::assertNotPushed(FulfillOrderJob::class);
    }
}
