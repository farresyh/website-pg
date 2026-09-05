<?php

namespace Tests\Feature\Services\Membership;

use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\MembershipQuotaDebit;
use App\Models\Order;
use App\Services\Membership\MembershipQuotaService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-027 Phase 6. Mirrors VoucherServiceTest::redeem()'s own coverage
 * shape — sufficient/insufficient balance, idempotency for the same
 * order.
 */
class MembershipQuotaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function membership(array $overrides = []): Membership
    {
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();

        return Membership::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 2000,
            'expires_at' => now()->addDays(20),
        ], $overrides));
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-QUOTA-1',
            'customer_email' => 'member@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1040,
            'transaction_fee' => 0,
            'final_amount' => 1040,
            'platform_profit' => 140,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ], $overrides));
    }

    public function test_decrement_reduces_quota_remaining_and_creates_a_debit_row(): void
    {
        $membership = $this->membership();
        $order = $this->order();

        $succeeded = app(MembershipQuotaService::class)->decrement($membership->id, $order->id, 1040);

        $this->assertTrue($succeeded);
        $this->assertSame(960, $membership->fresh()->quota_remaining_sen);
        $debit = MembershipQuotaDebit::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame($membership->id, $debit->membership_id);
        $this->assertSame(1040, $debit->amount_sen);
    }

    public function test_decrement_returns_false_and_does_not_touch_quota_when_insufficient(): void
    {
        $membership = $this->membership(['quota_remaining_sen' => 500]);
        $order = $this->order();

        $succeeded = app(MembershipQuotaService::class)->decrement($membership->id, $order->id, 1040);

        $this->assertFalse($succeeded);
        $this->assertSame(500, $membership->fresh()->quota_remaining_sen);
        $this->assertSame(0, MembershipQuotaDebit::query()->count());
    }

    public function test_decrement_is_idempotent_for_the_same_order(): void
    {
        $membership = $this->membership();
        $order = $this->order();

        $service = app(MembershipQuotaService::class);
        $service->decrement($membership->id, $order->id, 1040);
        $service->decrement($membership->id, $order->id, 1040);

        $this->assertSame(960, $membership->fresh()->quota_remaining_sen); // decremented once, not twice
        $this->assertSame(1, MembershipQuotaDebit::query()->where('order_id', $order->id)->count());
    }
}
