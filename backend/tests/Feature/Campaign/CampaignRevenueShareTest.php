<?php

namespace Tests\Feature\Campaign;

use App\Models\AccountingPeriodClose;
use App\Models\AdminUser;
use App\Models\BudgetEnvelope;
use App\Models\Campaign;
use App\Models\CampaignMonthStatement;
use App\Models\CashAccount;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\Voucher;
use App\Services\Accounting\MonthCloseService;
use App\Services\Accounting\MonthlyAccountingSummaryService;
use App\Services\Campaign\CampaignFeeSchedule;
use App\Services\Order\DeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-118 2026-11-19 addendum, V26–V34: a KOL fee as a share of the
 * recognised sales of the campaign's attributed orders, limited by the
 * campaign's profit and an optional cap, frozen in a statement at the
 * month close and voided with it on a reopen.
 */
class CampaignRevenueShareTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-05 02:00:00'); // 10:00 in Kuala Lumpur: November has ended
        $this->primaryAffiliate();
        $this->admin = AdminUser::factory()->create(['role' => 'super_admin']);
        BudgetEnvelope::query()->firstOrCreate(['name' => 'Capital Rolling']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_share_is_of_recognised_sales_of_attributed_orders_only(): void
    {
        $campaign = $this->campaign(fixed: 10_000, bp: 200);
        $code = $this->code($campaign);
        $other = $this->code($this->campaign(fixed: 0, bp: 0, name: 'Other'));

        $this->order($code, 30_000, profit: 2_000);                         // counted
        $this->order($code, 20_050, profit: 1_000, redeemed: false);        // a later order (V6): counted
        $this->order($code, 99_999, status: DeliveryStatus::Failed);        // never sales
        $this->order($code, 99_999, at: '2026-10-20 04:00:00');             // another month
        $this->order($code, 99_999, attributes: ['is_test' => true]);       // a test order
        $this->order($other, 99_999);                                       // another campaign
        $this->order(null, 99_999);                                         // no campaign

        // A settled partial order counts what it kept (RecognisedRevenue::keptSen()).
        $partial = $this->order($code, 10_000, profit: 300, status: DeliveryStatus::PartiallyDelivered);
        Voucher::query()->create(['affiliate_id' => $partial->affiliate_id, 'order_id' => $partial->id, 'code' => 'VP1', 'customer_email' => 'a@example.com', 'amount' => 4_000, 'remaining' => 4_000, 'status' => 'active', 'reason' => 'partial']);

        $sales = 30_000 + 20_050 + 6_000;
        $this->assertSame(10_000 + intdiv($sales * 200, 10_000), $this->schedule()->feeForMonth($campaign->fresh('fees'), '2026-11'));
        $this->assertSame(0, $this->schedule()->feeForMonth($campaign->fresh('fees'), '2026-10'), 'no row covers October');
    }

    public function test_the_share_never_exceeds_the_campaigns_profit_but_the_fixed_fee_is_owed_regardless(): void
    {
        $campaign = $this->campaign(fixed: 10_000, bp: 200);
        $code = $this->code($campaign);
        $this->order($code, 100_000, profit: 500); // share 2,000, profit 500

        $month = $this->schedule()->monthFigures($campaign->fresh('fees'), '2026-11');
        $this->assertSame(2_000, $month['share_before_limits_sen']);
        $this->assertSame(500, $month['share_sen']);
        $this->assertSame('profit', $month['limited_by']);
        $this->assertSame(10_500, $month['payable_sen']);

        // A loss month: the share is RM0, never negative; the fixed fee stands.
        $this->order($code, 1_000, profit: -900);
        $this->assertSame(10_000, $this->schedule()->feeForMonth($campaign->fresh('fees'), '2026-11'));
    }

    public function test_an_optional_cap_limits_the_share(): void
    {
        $campaign = $this->campaign(fixed: 0, bp: 200, cap: 1_500);
        $this->order($this->code($campaign), 100_000, profit: 9_000);

        $month = $this->schedule()->monthFigures($campaign->fresh('fees'), '2026-11');
        $this->assertSame(1_500, $month['payable_sen']);
        $this->assertSame('cap', $month['limited_by']);
    }

    public function test_the_percentage_is_limited_by_the_promo_margin_floor_and_the_floor_cannot_drop_below_it(): void
    {
        $this->assertRejected(fn () => $this->campaign(fixed: 0, bp: 201), 'revenue_share_bp');

        $campaign = $this->campaign(fixed: 0, bp: 200);

        // Lowering the floor under a running share would silently void V29's guarantee.
        Sanctum::actingAs($this->admin);
        $this->patchJson('/api/campaigns/settings', ['campaign_min_margin_percent' => 1.5])
            ->assertStatus(422)->assertJsonValidationErrors('campaign_min_margin_percent');
        $this->patchJson('/api/campaigns/settings', ['campaign_min_margin_percent' => 3])->assertOk();

        // Once the share has ended, the floor is free again.
        $this->schedule()->setEndMonth($campaign->fees->first(), '2026-12');
        Carbon::setTestNow('2027-01-05 02:00:00');
        $this->patchJson('/api/campaigns/settings', ['campaign_min_margin_percent' => 1])->assertOk();
    }

    public function test_the_close_freezes_a_statement_that_later_changes_cannot_move(): void
    {
        $campaign = $this->campaign(fixed: 10_000, bp: 200);
        $fixedOnly = $this->campaign(fixed: 5_000, bp: 0, name: 'Fixed');
        $code = $this->code($campaign);
        $this->order($code, 30_000, profit: 2_000, attributes: ['player_id' => '512345678927']);

        $close = $this->closeNovember();

        $statement = CampaignMonthStatement::query()->where('campaign_id', $campaign->id)->sole();
        $this->assertSame($close->id, $statement->accounting_period_close_id);
        $this->assertSame(10_600, $statement->payable_sen);
        $this->assertSame(30_000, $statement->sales_sen);
        $this->assertCount(1, $statement->lines);
        $this->assertStringNotContainsString('512345678927', json_encode($statement->lines), 'the player ID is masked');
        $this->assertSame(5_000, CampaignMonthStatement::query()->where('campaign_id', $fixedOnly->id)->sole()->payable_sen, 'a fixed-only campaign is frozen too');

        // Invariant: what the close expensed is what the statements froze.
        $this->assertSame(10_600 + 5_000, $close->profit_lines['kol_fees_accrued_sen']);

        // A late attribution stamp on an order recognised in the closed month (V34): the frozen figure stands.
        $this->order($code, 50_000, profit: 3_000);
        $this->assertSame(10_600, $this->schedule()->feeForMonth($campaign->fresh('fees'), '2026-11'));
        $this->assertSame(15_600, app(MonthlyAccountingSummaryService::class)->forPeriod(2026, 11)['kol_fees_accrued_sen']);

        $this->expectException(\LogicException::class);
        $statement->update(['payable_sen' => 1]);
    }

    public function test_a_reopen_voids_the_statement_and_the_re_close_freezes_a_new_one(): void
    {
        $campaign = $this->campaign(fixed: 0, bp: 200);
        $code = $this->code($campaign);
        $this->order($code, 30_000, profit: 2_000);
        $first = $this->closeNovember();

        app(MonthCloseService::class)->reopen($first, 'late order', $this->admin->id);
        $this->assertNotNull(CampaignMonthStatement::query()->sole()->voided_at);
        $this->assertSame(600, $this->schedule()->feeForMonth($campaign->fresh('fees'), '2026-11'), 'a reopened month is live again');

        $this->order($code, 30_000, profit: 2_000);
        $second = $this->closeNovember();

        $active = CampaignMonthStatement::query()->whereNull('voided_at')->sole();
        $this->assertSame($second->id, $active->accounting_period_close_id);
        $this->assertSame(1_200, $active->payable_sen);
        $this->assertSame(2, CampaignMonthStatement::query()->count(), 'the voided statement stays for audit');
    }

    public function test_the_payment_preview_covers_months_by_the_fixed_fee_only(): void
    {
        Carbon::setTestNow('2026-11-05 02:00:00');
        $campaign = $this->campaign(fixed: 10_000, bp: 200);

        $preview = $this->schedule()->paymentPreview($campaign->fresh('fees'), 30_000, '2026-11-05');

        $this->assertSame('2027-01', $preview['covers_until']);
    }

    public function test_closed_months_read_frozen_rows_in_a_bounded_number_of_queries(): void
    {
        $campaigns = collect(range(1, 5))->map(fn (int $i) => $this->campaign(fixed: 1_000, bp: 100, name: "KOL {$i}"));
        foreach ($campaigns as $c) {
            $this->order($this->code($c), 10_000, profit: 1_000);
        }
        $this->closeNovember();

        $schedule = $this->schedule();
        $loaded = Campaign::query()->with('fees')->get();
        DB::enableQueryLog();
        $loaded->each(fn (Campaign $c) => $schedule->accruedThroughSen($c, '2026-12'));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $queries, 'one frozen read plus one live read of December, never per campaign per month');
    }

    public function test_the_statement_pdf_is_available_for_a_closed_month(): void
    {
        $campaign = $this->campaign(fixed: 0, bp: 200);
        $this->order($this->code($campaign), 30_000, profit: 2_000);
        $this->closeNovember();
        $statement = CampaignMonthStatement::query()->sole();

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertJsonPath('terms.revenue_share_bp', 200)
            ->assertJsonPath('statements.0.id', $statement->id)
            ->assertJsonPath('statements.0.payable_sen', 600);

        $this->get("/api/campaign-statements/{$statement->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    private function schedule(): CampaignFeeSchedule
    {
        return app(CampaignFeeSchedule::class);
    }

    private function campaign(int $fixed, int $bp, ?int $cap = null, string $name = 'KOL', string $from = '2026-11'): Campaign
    {
        return $this->schedule()->createCampaign($name, $fixed, $from, null, $this->admin->id, $bp, $cap);
    }

    private function code(Campaign $campaign): PromoCode
    {
        static $n = 0;

        return PromoCode::query()->create(['campaign_id' => $campaign->id, 'code' => 'KOL'.(++$n), 'discount_percent' => 5, 'budget_sen' => 100_000]);
    }

    private function order(?PromoCode $code, int $selling, int $profit = 0, DeliveryStatus $status = DeliveryStatus::Delivered, string $at = '2026-11-10 04:00:00', bool $redeemed = true, array $attributes = []): Order
    {
        return Order::factory()->create([
            'selling_price' => $selling, 'platform_profit' => $profit, 'cost_price' => 0, 'transaction_fee' => 0, 'final_amount' => $selling,
            'delivery_status' => $status, 'paid_at' => $at, 'delivered_at' => $at, 'recognised_at' => $status === DeliveryStatus::Failed ? null : $at,
            'promo_code_id' => $redeemed ? $code?->id : null, 'attributed_promo_code_id' => $code?->id,
            ...$attributes,
        ]);
    }

    private function closeNovember(): AccountingPeriodClose
    {
        foreach (['2026-09-01', '2026-10-01'] as $month) {
            AccountingPeriodClose::query()->firstOrCreate(['period_month' => $month], [
                'operating_profit_sen' => 0, 'prior_adjustment_sen' => 0, 'allocated_sen' => 0,
                'profit_lines' => [], 'cash_balances' => [], 'equation' => [], 'gap_sen' => 0, 'closed_by' => $this->admin->id,
            ]);
        }

        $closes = app(MonthCloseService::class);
        $allocate = $closes->preview(2026, 11)['allocate_sen'];
        $lines = $allocate === 0 ? [] : [['budget_envelope_id' => BudgetEnvelope::query()->value('id'), 'amount_sen' => $allocate]];
        $cash = CashAccount::query()->where('is_active', true)->get()->map(fn (CashAccount $a) => ['cash_account_id' => $a->id, 'balance_sen' => 0])->all();

        return $closes->close(2026, 11, $lines, $cash, 'test', $this->admin->id);
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
}
