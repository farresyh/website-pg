<?php

namespace Tests\Feature\Services\Reseller;

use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerMembershipTier;
use App\Models\ResellerSubscription;
use App\Services\Ledger\LedgerService;
use App\Services\Order\PaymentStatus;
use App\Services\Reseller\ResellerEarningsService;
use App\Services\Reseller\ResellerSubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-059 decision 5: the single seam for every reseller-portal money
 * read. It must key strictly off polymorphic `owner_type='reseller'` /
 * `owner_id` — `ledger_entries` has no `reseller_id` scope to lean on.
 */
class ResellerEarningsServiceTest extends TestCase
{
    use RefreshDatabase;

    private ResellerEarningsService $earnings;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->earnings = app(ResellerEarningsService::class);
        $this->ledger = app(LedgerService::class);
    }

    private function reseller(string $name = 'Acme Resell'): Reseller
    {
        return Reseller::query()->create([
            'business_name' => $name,
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
        ]);
    }

    public function test_balance_sums_only_this_resellers_ledger_entries(): void
    {
        $mine = $this->reseller('Mine');
        $other = $this->reseller('Other');

        $this->ledger->credit('reseller', $mine->id, 5000, 'order_profit', 'order', 1);
        $this->ledger->credit('reseller', $mine->id, -1200, 'withdrawal', 'withdrawal', 1);
        $this->ledger->credit('reseller', $other->id, 9999, 'order_profit', 'order', 2);
        $this->ledger->credit('platform', null, 7777, 'order_profit', 'order', 3);

        $this->assertSame(3800, $this->earnings->balance($mine));
        $this->assertSame(9999, $this->earnings->balance($other));
    }

    public function test_ledger_entries_returns_only_this_reseller_newest_first_without_admin_ids(): void
    {
        $mine = $this->reseller('Mine');
        $other = $this->reseller('Other');

        $this->ledger->credit('reseller', $mine->id, 1000, 'order_profit', 'order', 1, 42);
        $this->ledger->credit('reseller', $mine->id, -300, 'reseller_tier_fee', 'reseller_subscription', 1, 42);
        $this->ledger->credit('reseller', $other->id, 500, 'order_profit', 'order', 2);

        $page = $this->earnings->ledgerEntries($mine);

        $this->assertCount(2, $page->items());
        $this->assertSame('reseller_tier_fee', $page->items()[0]['type']);
        $this->assertSame('order_profit', $page->items()[1]['type']);
        $this->assertArrayNotHasKey('created_by', $page->items()[0]);
        $this->assertArrayNotHasKey('owner_id', $page->items()[0]);
    }

    public function test_dashboard_stats_aggregate_only_this_resellers_paid_non_test_orders(): void
    {
        $mine = $this->reseller('Mine');
        $other = $this->reseller('Other');

        // counts: paid, not test, has paid_at, this month
        Order::factory()->forReseller($mine)->create(['final_amount' => 2000, 'paid_at' => now()]);
        Order::factory()->forReseller($mine)->create(['final_amount' => 3000, 'paid_at' => now()]);
        // excluded: pending
        Order::factory()->forReseller($mine)->pending()->create(['final_amount' => 9999, 'paid_at' => null]);
        // excluded: test order
        Order::factory()->forReseller($mine)->create(['final_amount' => 9999, 'is_test' => true, 'paid_at' => now()]);
        // excluded: another reseller
        Order::factory()->forReseller($other)->create(['final_amount' => 5000, 'paid_at' => now()]);

        $stats = $this->earnings->dashboardStats($mine);

        $this->assertSame(2, $stats['this_month']['orders']);
        $this->assertSame(5000, $stats['this_month']['sales']);
        $this->assertSame(0, $stats['earnings_balance']);
        $this->assertNull($stats['subscription']);
    }

    public function test_dashboard_stats_today_bucket_excludes_older_paid_orders(): void
    {
        $mine = $this->reseller('Mine');

        Order::factory()->forReseller($mine)->create(['final_amount' => 1000, 'paid_at' => now()]);
        Order::factory()->forReseller($mine)->create(['final_amount' => 4000, 'paid_at' => now()->subDays(3)]);

        $stats = $this->earnings->dashboardStats($mine);

        $this->assertSame(1, $stats['today']['orders']);
        $this->assertSame(1000, $stats['today']['sales']);
        $this->assertSame(2, $stats['this_month']['orders']);
    }

    public function test_dashboard_stats_includes_the_subscription_snapshot_when_present(): void
    {
        $mine = $this->reseller('Mine');
        $tier = ResellerMembershipTier::query()->create([
            'name' => 'Silver', 'monthly_fee_sen' => 4900, 'markup_percent' => 5, 'is_active' => true,
        ]);
        ResellerSubscription::query()->create([
            'reseller_id' => $mine->id,
            'reseller_membership_tier_id' => $tier->id,
            'status' => ResellerSubscriptionStatus::Active,
            'current_period_started_at' => now(),
            'next_charge_at' => now()->addDays(30),
        ]);

        $stats = $this->earnings->dashboardStats($mine);

        $this->assertSame('Silver', $stats['subscription']['tier_name']);
        $this->assertSame('active', $stats['subscription']['status']);
        $this->assertSame(4900, $stats['subscription']['monthly_fee_sen']);
    }

    public function test_tier_fee_history_returns_only_fee_debits_for_this_reseller(): void
    {
        $mine = $this->reseller('Mine');
        $other = $this->reseller('Other');

        $this->ledger->credit('reseller', $mine->id, 8000, 'order_profit', 'order', 1);
        $this->ledger->credit('reseller', $mine->id, -4900, 'reseller_tier_fee', 'reseller_subscription', 1);
        $this->ledger->credit('reseller', $mine->id, -4900, 'reseller_tier_fee', 'reseller_subscription', 1);
        $this->ledger->credit('reseller', $other->id, -1000, 'reseller_tier_fee', 'reseller_subscription', 2);

        $history = $this->earnings->tierFeeHistory($mine);

        $this->assertCount(2, $history);
        $this->assertSame(-4900, $history[0]['amount']);
    }

    public function test_paid_status_is_matched_by_value_not_enum_instance(): void
    {
        // guards against the enum-vs-string comparison trap this codebase
        // has hit (CustomerAnalyticsService's own note).
        $mine = $this->reseller('Mine');
        Order::factory()->forReseller($mine)->create([
            'payment_status' => PaymentStatus::Paid,
            'final_amount' => 1234,
            'paid_at' => now(),
        ]);

        $this->assertSame(1234, $this->earnings->dashboardStats($mine)['this_month']['sales']);
    }
}
