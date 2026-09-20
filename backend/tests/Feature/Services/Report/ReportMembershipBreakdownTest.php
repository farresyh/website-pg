<?php

namespace Tests\Feature\Services\Report;

use App\Models\Affiliate;
use App\Models\Membership;
use App\Models\Order;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Report\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-027 continued addendum decision 15 / Phase 6.5 (grilled
 * 2026-08-29, Q10) — locks in the member/standard split, the margin-
 * forgone definition (Σ normal_selling_price − selling_price over member
 * orders), and the membership_fee revenue line (Σ ledger type).
 */
class ReportMembershipBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 1000,
            'standard_selling_price' => 1150,
            'selling_price' => 1150,
            'transaction_fee' => 100,
            'final_amount' => 1250,
            'platform_profit' => 150,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
            'delivery_status' => DeliveryStatus::Delivered->value,
            'is_test' => false,
        ], $overrides));
    }

    public function test_splits_member_and_standard_sales_and_computes_margin_forgone(): void
    {
        // Member order: paid member price 1030 vs normal retail 1150.
        $this->order([
            'pricing_basis' => 'member',
            'selling_price' => 1030,
            'normal_selling_price' => 1150,
            'final_amount' => 1130, // 1030 + 100 fee
        ]);

        // Standard order.
        $this->order([
            'pricing_basis' => 'standard',
            'normal_selling_price' => null,
            'final_amount' => 1250,
        ]);

        $membership = Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'member@example.com',
            'membership_plan_id' => 1,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 10000,
            'expires_at' => now()->addDays(30),
        ]);

        (new LedgerService)->credit('platform', null, 890, 'membership_fee', 'membership', $membership->id);

        $row = (new ReportService)->membershipBreakdown(null, null, null);

        $this->assertSame(1130, $row['member_sales']);
        $this->assertSame(1, $row['member_orders_count']);
        $this->assertSame(1250, $row['standard_sales']);
        $this->assertSame(1, $row['standard_orders_count']);
        $this->assertSame(120, $row['margin_forgone']); // 1150 - 1030
        $this->assertSame(890, $row['membership_fee_revenue']);
    }

    public function test_excludes_non_paid_and_test_orders_from_the_split(): void
    {
        $this->order(['pricing_basis' => 'member', 'selling_price' => 1030, 'normal_selling_price' => 1150]);
        $this->order(['pricing_basis' => 'member', 'payment_status' => PaymentStatus::Pending->value, 'paid_at' => null, 'selling_price' => 1030, 'normal_selling_price' => 1150, 'final_amount' => 5000]);
        $this->order(['pricing_basis' => 'member', 'is_test' => true, 'selling_price' => 1030, 'normal_selling_price' => 1150, 'final_amount' => 9999]);

        $row = (new ReportService)->membershipBreakdown(null, null, null);

        $this->assertSame(1, $row['member_orders_count']);
        $this->assertSame(0, $row['standard_orders_count']);
        $this->assertSame(120, $row['margin_forgone']);
    }

    /**
     * 2026-09-21 fix (ADR-086 addendum) — `pricing_basis` has 4 values,
     * not 2. The old `!= 'member'` "standard" bucket silently absorbed
     * reseller-wallet and affiliate-wholesale-tier orders (found live:
     * "Standard (guest)" showed 93% wholesale reseller volume in prod).
     * Both non-standard, non-member bases must be excluded entirely from
     * this breakdown — they have their own dedicated tabs
     * (resellerBreakdown()/affiliateBreakdown()).
     */
    public function test_excludes_reseller_wallet_and_affiliate_tier_orders_from_standard_bucket(): void
    {
        $this->order(['pricing_basis' => 'standard', 'normal_selling_price' => null, 'final_amount' => 1250]);
        $this->order(['pricing_basis' => 'reseller-wallet', 'normal_selling_price' => null, 'final_amount' => 46048]);
        $this->order(['pricing_basis' => 'affiliate', 'normal_selling_price' => null, 'final_amount' => 5000]);

        $row = (new ReportService)->membershipBreakdown(null, null, null);

        $this->assertSame(1250, $row['standard_sales']);
        $this->assertSame(1, $row['standard_orders_count']);
    }

    /**
     * 2026-09-21 fix (ADR-086 addendum) — `membership_fee_revenue` never
     * applied the `$affiliateId` filter every other figure in this
     * breakdown does, even though membership is genuinely per-affiliate
     * (`memberships.(affiliate_id, email)`, ADR-061 PR-B decision 5).
     * Scoping now joins through `memberships` the same way the LLM
     * Report Assistant's own `llm_report_membership_fees` view already
     * does, so the two never drift.
     */
    public function test_membership_fee_revenue_is_scoped_by_affiliate(): void
    {
        $affiliateA = $this->primaryAffiliate();
        $affiliateB = Affiliate::query()->create([
            'business_name' => 'Ohahastore',
            'markup_pct' => 0,
            'status' => 'active',
            'is_owned' => false,
        ]);

        $membershipA = Membership::query()->create([
            'affiliate_id' => $affiliateA->id,
            'email' => 'member-a@example.com',
            'membership_plan_id' => 1,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 10000,
            'expires_at' => now()->addDays(30),
        ]);

        $membershipB = Membership::query()->create([
            'affiliate_id' => $affiliateB->id,
            'email' => 'member-b@example.com',
            'membership_plan_id' => 1,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 10000,
            'expires_at' => now()->addDays(30),
        ]);

        (new LedgerService)->credit('platform', null, 890, 'membership_fee', 'membership', $membershipA->id);
        (new LedgerService)->credit('platform', null, 1990, 'membership_fee', 'membership', $membershipB->id);

        $rowA = (new ReportService)->membershipBreakdown(null, null, $affiliateA->id);
        $rowB = (new ReportService)->membershipBreakdown(null, null, $affiliateB->id);
        $rowAll = (new ReportService)->membershipBreakdown(null, null, null);

        $this->assertSame(890, $rowA['membership_fee_revenue']);
        $this->assertSame(1990, $rowB['membership_fee_revenue']);
        $this->assertSame(2880, $rowAll['membership_fee_revenue']);
    }
}
