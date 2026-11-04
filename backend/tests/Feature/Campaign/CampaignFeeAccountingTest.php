<?php

namespace Tests\Feature\Campaign;

use App\Models\AccountingPeriodClose;
use App\Models\AdminUser;
use App\Models\BudgetEnvelope;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\Order;
use App\Services\Accounting\BudgetEnvelopeService;
use App\Services\Accounting\EnvelopePostingType;
use App\Services\Accounting\ExpenseCategory;
use App\Services\Accounting\MonthCloseService;
use App\Services\Accounting\MonthlyAccountingSummaryService;
use App\Services\Campaign\CampaignFeeSchedule;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADR-118 V12 + V13 (the accountant's rule): a KOL fee is an advertising
 * expense in its month of service. The schedule is the agreement, a
 * campaign-tagged Envelope payment is the cash, and their difference is
 * the prepaid / (accrued) balance. None of it moves operating profit or
 * the month-close allocation: the payment already left its envelope.
 */
class CampaignFeeAccountingTest extends TestCase
{
    use RefreshDatabase;

    private int $adminId;

    private BudgetEnvelope $envelope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryAffiliate();
        $this->adminId = AdminUser::factory()->create(['role' => 'super_admin'])->id;
        $this->envelope = BudgetEnvelope::query()->create(['name' => 'Marketing']);
    }

    public function test_the_fee_for_a_month_follows_the_latest_row_until_it_ends(): void
    {
        $campaign = $this->schedule()->createCampaign('KOL 1', 50_000, '2027-01', null, $this->adminId);
        $this->schedule()->addFee($campaign, 150_000, '2027-03', '2027-04', $this->adminId);
        $campaign->load('fees');

        $this->assertSame(0, $this->schedule()->feeForMonth($campaign, '2026-12'));
        $this->assertSame(50_000, $this->schedule()->feeForMonth($campaign, '2027-02'));
        $this->assertSame(150_000, $this->schedule()->feeForMonth($campaign, '2027-04'));
        $this->assertSame(0, $this->schedule()->feeForMonth($campaign, '2027-05'));
        $this->assertSame(50_000 * 2 + 150_000 * 2, $this->schedule()->accruedThroughSen($campaign, '2027-06'));
    }

    public function test_paying_three_months_ahead_is_a_prepayment_used_up_month_by_month(): void
    {
        $campaign = $this->schedule()->createCampaign('KOL 1', 50_000, '2027-01', null, $this->adminId);
        $this->assertSame(['balance_before_sen' => -50_000, 'balance_after_sen' => 100_000, 'covers_until' => '2027-03'], $this->schedule()->paymentPreview($campaign, 150_000, '2027-01-05'));

        $this->pay($campaign, 150_000, '2027-01-05');

        $this->assertSame(100_000, $this->schedule()->balanceSen($campaign, '2027-01'));
        $this->assertSame(50_000, $this->schedule()->balanceSen($campaign, '2027-02'));
        $this->assertSame(0, $this->schedule()->balanceSen($campaign, '2027-03'));
        // Not paid for April: accrued, owed to the KOL.
        $this->assertSame(-50_000, $this->schedule()->balanceSen($campaign, '2027-04'));
    }

    public function test_a_voided_payment_keeps_its_campaign_so_the_paid_total_nets_to_zero(): void
    {
        $campaign = $this->schedule()->createCampaign('KOL 1', 50_000, '2027-01', null, $this->adminId);
        $posting = $this->pay($campaign, 50_000, '2027-01-05');

        $reversal = app(BudgetEnvelopeService::class)->void($posting, 'wrong campaign', $this->adminId);

        $this->assertSame($campaign->id, $reversal->campaign_id);
        $this->assertSame(0, $this->schedule()->paidThroughSen($campaign, '2027-12-31'));
    }

    public function test_only_an_advertising_expense_can_be_a_campaign_payment(): void
    {
        $campaign = $this->schedule()->createCampaign('KOL 1', 50_000, '2027-01', null, $this->adminId);

        $this->expectException(ValidationException::class);
        app(BudgetEnvelopeService::class)->post(
            EnvelopePostingType::Expense, [['budget_envelope_id' => $this->envelope->id, 'amount_sen' => -50_000]], '2026-11-01',
            'Software', $this->adminId, expenseCategory: ExpenseCategory::Software, campaignId: $campaign->id,
        );
    }

    public function test_nothing_that_touches_a_closed_month_can_change(): void
    {
        $this->closeMonth('2026-09-01');
        $this->assertRejected(fn () => $this->schedule()->createCampaign('KOL 2', 50_000, '2026-09', null, $this->adminId), 'from_month');

        $campaign = $this->schedule()->createCampaign('KOL 1', 50_000, '2026-10', null, $this->adminId);
        $fee = $campaign->fees->first();
        $this->closeMonth('2026-11-01'); // October and November are now closed too

        // Ending it in October would remove November's fee from a closed month: refused.
        $this->assertRejected(fn () => $this->schedule()->setEndMonth($fee, '2026-10'), 'to_month');
        // Ending it in November changes only December onwards: allowed.
        $this->schedule()->setEndMonth($fee, '2026-11');
        $this->assertSame('2026-11-01', $fee->fresh()->to_month->toDateString());
        $this->assertRejected(fn () => $this->schedule()->addFee($campaign, 10_000, '2026-11', null, $this->adminId), 'from_month');
    }

    public function test_the_summary_expenses_a_kol_fee_by_its_schedule_and_never_in_operating_profit(): void
    {
        $now = Carbon::now('Asia/Kuala_Lumpur');
        $before = app(MonthlyAccountingSummaryService::class)->forPeriod($now->year, $now->month);

        $campaign = $this->schedule()->createCampaign('KOL 1', 50_000, $now->format('Y-m'), null, $this->adminId);
        $this->pay($campaign, 150_000, $now->format('Y-m').'-01');
        app(BudgetEnvelopeService::class)->post(
            EnvelopePostingType::Expense, [['budget_envelope_id' => $this->envelope->id, 'amount_sen' => -3_000]], $now->format('Y-m').'-01',
            'Canva', $this->adminId, expenseCategory: ExpenseCategory::Software,
        );

        $lines = app(MonthlyAccountingSummaryService::class)->forPeriod($now->year, $now->month);

        $this->assertSame(50_000, $lines['kol_fees_accrued_sen']);
        $this->assertSame(150_000, $lines['kol_fees_paid_sen']);
        $this->assertSame(100_000, $lines['kol_prepaid_balance_sen']);
        $this->assertSame(3_000, $lines['envelope_manual_expenses_sen'], 'a KOL payment is not a plain envelope expense');
        $this->assertSame($before['operating_profit_sen'], $lines['operating_profit_sen'], 'the allocation never sees the KOL fee');

        $preview = app(MonthCloseService::class)->preview($now->year, $now->month);
        $this->assertSame($lines['operating_profit_sen'] - 3_000 - 50_000, $preview['net_sen']);
    }

    public function test_gross_sales_less_the_promo_discount_is_the_recognised_revenue(): void
    {
        $now = Carbon::now('Asia/Kuala_Lumpur');
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $this->order($game, ['selling_price' => 950, 'promo_discount_sen' => 50]);
        $this->order($game, ['selling_price' => 500]);

        $lines = app(MonthlyAccountingSummaryService::class)->forPeriod($now->year, $now->month);

        $this->assertSame(1450, $lines['sales_revenue_sen']);
        $this->assertSame(50, $lines['promo_discounts_sen']);
        $this->assertSame(1500, $lines['gross_sales_sen']);
    }

    private function schedule(): CampaignFeeSchedule
    {
        return app(CampaignFeeSchedule::class);
    }

    private function pay(Campaign $campaign, int $amountSen, string $date)
    {
        return app(BudgetEnvelopeService::class)->post(
            EnvelopePostingType::Expense, [['budget_envelope_id' => $this->envelope->id, 'amount_sen' => -$amountSen]], $date,
            "KOL fee {$campaign->name}", $this->adminId, expenseCategory: ExpenseCategory::Advertising, campaignId: $campaign->id,
        );
    }

    private function closeMonth(string $month): void
    {
        AccountingPeriodClose::query()->create([
            'period_month' => $month,
            'operating_profit_sen' => 0, 'prior_adjustment_sen' => 0, 'allocated_sen' => 0,
            'profit_lines' => [], 'cash_balances' => [], 'equation' => [], 'gap_sen' => 0,
            'closed_by' => $this->adminId,
        ]);
    }

    private function assertRejected(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail("Expected a rejection on {$field}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    private function order(Game $game, array $overrides): Order
    {
        return Order::query()->create(array_merge([
            'placed_via' => 'storefront', 'order_number' => 'PG-'.Str::upper(Str::random(10)),
            'affiliate_id' => $this->primaryAffiliate()->id, 'game_id' => $game->id,
            'customer_email' => 'b@example.com', 'customer_name' => 'B', 'player_id' => '1',
            'cost_price' => 400, 'standard_selling_price' => 500, 'transaction_fee' => 0, 'final_amount' => 0,
            'platform_profit' => 0, 'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value, 'paid_at' => now(),
            'delivery_status' => DeliveryStatus::Delivered->value, 'delivered_at' => now(), 'recognised_at' => now(),
        ], $overrides));
    }
}
