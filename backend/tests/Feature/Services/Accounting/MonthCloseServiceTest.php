<?php

namespace Tests\Feature\Services\Accounting;

use App\Models\AccountingPeriodClose;
use App\Models\AdminUser;
use App\Models\BudgetEnvelope;
use App\Models\CashAccount;
use App\Models\Order;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use App\Models\Voucher;
use App\Services\Accounting\BudgetEnvelopeService;
use App\Services\Accounting\CashPositionService;
use App\Services\Accounting\EnvelopePostingType;
use App\Services\Accounting\ExpenseCategory;
use App\Services\Accounting\FundType;
use App\Services\Accounting\MonthCloseService;
use App\Services\Accounting\PaidFrom;
use App\Services\Accounting\SupplierLedgerEntryType;
use App\Services\Order\DeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADR-083 2026-10-10 addendum, decisions 9–14 — the month close.
 */
class MonthCloseServiceTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private BudgetEnvelope $capital;

    private BudgetEnvelope $marketing;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->admin = AdminUser::factory()->create();
        $this->capital = BudgetEnvelope::query()->firstOrCreate(['name' => 'Capital Rolling']);
        $this->marketing = BudgetEnvelope::query()->firstOrCreate(['name' => 'Marketing']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function closes(): MonthCloseService
    {
        return app(MonthCloseService::class);
    }

    private function cash(int $sen): array
    {
        return [['cash_account_id' => CashAccount::query()->value('id'), 'balance_sen' => $sen]];
    }

    private function deliveredOrder(int $selling, string $paidAt, array $attributes = []): Order
    {
        return Order::factory()->delivered()->create([
            'selling_price' => $selling, 'cost_price' => 0, 'transaction_fee' => 0, 'final_amount' => $selling,
            'paid_at' => $paidAt, 'delivered_at' => $paidAt, ...$attributes,
        ]);
    }

    private function close(int $year, int $month, int $allocateToCapital, int $cash = 0, ?string $note = 'test'): AccountingPeriodClose
    {
        $lines = $allocateToCapital === 0 ? [] : [['budget_envelope_id' => $this->capital->id, 'amount_sen' => $allocateToCapital]];

        return $this->closes()->close($year, $month, $lines, $this->cash($cash), $note, $this->admin->id);
    }

    private function assertRejected(string $key, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected a validation error on {$key}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }

    /**
     * Decision 13's identity on a whole month that touches every module:
     * director loan, supplier top-up, a delivered order drawn from the
     * supplier, an order still in flight, a failed order compensated with a
     * voucher, goodwill, an envelope expense, CHIP not yet settled. Assets
     * and claims must meet exactly, and the envelope check must agree.
     */
    public function test_cash_equation_balances_to_zero_across_every_module(): void
    {
        $envelopes = new BudgetEnvelopeService;
        $envelopes->post(EnvelopePostingType::Funding, [['budget_envelope_id' => $this->capital->id, 'amount_sen' => 100000]], '2026-09-15', 'loan', $this->admin->id, counterparty: PaidFrom::directors()[0], fundType: FundType::Loan);

        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
        $transfer = SupplierTransfer::query()->create([
            'transferred_on' => '2026-09-16', 'supplier_id' => $supplier->id, 'source_channel' => 'wise',
            'amount_myr_sent' => 50000, 'fee_myr' => 0, 'currency' => 'IDR', 'amount_foreign_received' => '50000.0000',
        ]);
        $supplierEntry = fn (string $type, string $amount, string $refType, int $refId, string $at) => SupplierLedgerEntry::query()->forceCreate([
            'supplier_id' => $supplier->id, 'type' => $type, 'amount' => $amount, 'currency' => 'IDR',
            'reference_type' => $refType, 'reference_id' => $refId, 'created_at' => $at,
        ]);
        $supplierEntry(SupplierLedgerEntryType::Topup->value, '50000', 'supplier_transfer', $transfer->id, '2026-09-16 02:00:00');

        $chip = fn (array $attrs) => ['payment_gateway' => 'chip', 'payment_ref' => 'chip-'.uniqid(), 'transaction_fee' => 100, 'final_amount' => $attrs['selling_price'] + 100, ...$attrs];

        $delivered = Order::factory()->delivered()->create($chip(['selling_price' => 10000, 'cost_price' => 9000, 'supplier_id' => $supplier->id, 'paid_at' => '2026-09-20 02:00:00', 'delivered_at' => '2026-09-20 02:00:00']));
        $supplierEntry(SupplierLedgerEntryType::OrderDrawdown->value, '-9000', 'order', $delivered->id, '2026-09-20 02:00:00');

        Order::factory()->create($chip(['selling_price' => 2000, 'cost_price' => 1800, 'paid_at' => '2026-09-25 02:00:00', 'delivery_status' => DeliveryStatus::Pending]));

        $failed = Order::factory()->create($chip(['selling_price' => 3000, 'cost_price' => 2700, 'paid_at' => '2026-09-26 02:00:00', 'delivery_status' => DeliveryStatus::Failed]));
        $voucher = fn (array $attrs) => tap(Voucher::query()->create([
            'affiliate_id' => $failed->affiliate_id, 'code' => 'V'.uniqid(), 'customer_email' => 'a@example.com', 'status' => 'active', 'reason' => 'x', ...$attrs,
        ]))->forceFill(['created_at' => '2026-09-26 03:00:00'])->save();
        $voucher(['order_id' => $failed->id, 'amount' => 3000, 'remaining' => 3000]);
        $voucher(['amount' => 200, 'remaining' => 200]); // goodwill

        $envelopes->post(EnvelopePostingType::Expense, [['budget_envelope_id' => $this->marketing->id, 'amount_sen' => -500]], '2026-09-28', 'ads', $this->admin->id, expenseCategory: ExpenseCategory::Advertising);

        $preview = $this->closes()->preview(2026, 9);
        // 10000 revenue − 9000 cost (the drawdown at the blended RM0.01 rate) − 200 goodwill.
        $this->assertSame(800, $preview['operating_profit_sen']);
        $this->assertSame(800, $preview['allocate_sen']);

        // Cash: 100000 loan in − 50000 to the supplier − 500 expense.
        $close = $this->close(2026, 9, 800, cash: 49500, note: null);

        $equation = $close->equation;
        $this->assertSame(41000, $equation['assets']['supplier_prepaid_sen']); // 41,000 units left × RM0.01
        $this->assertSame(10100 + 2100 + 3100 - 3 * 100, $equation['assets']['chip_unsettled_net_sen']);
        $this->assertSame(100000 - 500 + 800, $equation['claims']['envelopes_sen']);
        $this->assertSame(2000, $equation['claims']['orders_undelivered_sen']);
        $this->assertSame(3000 + 200, $equation['claims']['vouchers_outstanding_sen']);
        $this->assertSame(0, $close->gap_sen);
        $this->assertSame($equation['claims']['envelopes_sen'], $equation['envelope_identity_sen']);
    }

    public function test_close_allocates_exactly_the_operating_profit_dated_on_the_last_day(): void
    {
        $this->deliveredOrder(1500, '2026-09-10 02:00:00');

        $this->assertRejected('lines', fn () => $this->close(2026, 9, 1400));

        $close = $this->closes()->close(2026, 9, [
            ['budget_envelope_id' => $this->capital->id, 'amount_sen' => 1000],
            ['budget_envelope_id' => $this->marketing->id, 'amount_sen' => 500],
        ], $this->cash(0), 'gap explained', $this->admin->id);

        $posting = $close->posting;
        $this->assertSame(EnvelopePostingType::ProfitAllocation, $posting->type);
        $this->assertSame('2026-09-30', $posting->transaction_date->toDateString());
        $this->assertSame(1500, $close->allocated_sen);
        $this->assertSame(1000, $this->capital->balanceSen());
        $this->assertSame(500, $this->marketing->balanceSen());
    }

    /** Decision 10: a loss month allocates negatively. */
    public function test_a_loss_month_allocates_negatively(): void
    {
        Voucher::query()->create(['affiliate_id' => $this->primaryAffiliate()->id, 'code' => 'GW1', 'customer_email' => 'a@example.com', 'amount' => 700, 'remaining' => 700, 'status' => 'active', 'reason' => 'goodwill'])
            ->forceFill(['created_at' => '2026-09-10 00:00:00'])->save();

        $close = $this->close(2026, 9, -700);

        $this->assertSame(-700, $close->allocated_sen);
        $this->assertSame(-700, $this->capital->balanceSen());
    }

    public function test_a_zero_month_closes_without_a_posting(): void
    {
        $close = $this->close(2026, 9, 0);

        $this->assertNull($close->budget_envelope_posting_id);
    }

    public function test_months_close_in_order_once_ended_in_kuala_lumpur_and_only_once(): void
    {
        $this->assertRejected('period', fn () => $this->close(2026, 8, 0)); // before the first period
        $this->assertRejected('period', fn () => $this->close(2026, 10, 0)); // September not closed yet

        Carbon::setTestNow('2026-09-30 15:59:59'); // 30 Sep 23:59:59 KL
        $this->assertRejected('period', fn () => $this->close(2026, 9, 0));

        Carbon::setTestNow('2026-09-30 16:00:00'); // 1 Oct 00:00 KL
        $this->close(2026, 9, 0);
        $this->assertRejected('period', fn () => $this->close(2026, 9, 0));
    }

    /** Decision 12: a closed month is never rewritten; its drift is carried into the next close. */
    public function test_drift_in_a_closed_month_is_carried_into_the_next_close(): void
    {
        $this->deliveredOrder(1000, '2026-09-10 02:00:00');
        $this->close(2026, 9, 1000);

        // A late correction lands in September after its close.
        $this->deliveredOrder(300, '2026-09-29 02:00:00');
        $this->deliveredOrder(2000, '2026-10-10 02:00:00');

        $preview = $this->closes()->preview(2026, 10);
        $this->assertSame(2000, $preview['operating_profit_sen']);
        $this->assertSame(300, $preview['prior_adjustment_sen']);
        $this->assertSame(300, $this->closes()->preview(2026, 9)['close']['drift_sen']);

        $october = $this->close(2026, 10, 2300);
        $this->assertSame(2000, $october->operating_profit_sen);
        $this->assertSame(300, $october->prior_adjustment_sen);
        $this->assertSame(1000, AccountingPeriodClose::query()->whereDate('period_month', '2026-09-01')->value('allocated_sen'));

        // Already carried: the next close owes nothing more for September.
        $this->assertSame(0, $this->closes()->preview(2026, 11)['prior_adjustment_sen']);
    }

    public function test_only_the_latest_close_reopens_and_it_voids_the_allocation(): void
    {
        $this->deliveredOrder(1000, '2026-09-10 02:00:00');
        $september = $this->close(2026, 9, 1000);
        $october = $this->close(2026, 10, 0);

        $this->assertRejected('close', fn () => $this->closes()->reopen($september, 'wrong', $this->admin->id));
        $this->assertRejected('posting', fn () => (new BudgetEnvelopeService)->void($september->posting, 'direct', $this->admin->id));

        $this->closes()->reopen($october, 'redo', $this->admin->id);
        $this->closes()->reopen($september, 'redo', $this->admin->id);

        $this->assertSame(0, $this->capital->balanceSen());
        $this->assertNotNull($september->fresh()->voided_at);
        $this->assertNull($this->closes()->preview(2026, 9)['blocked_reason']);

        $again = $this->close(2026, 9, 1000);
        $this->assertSame(0, $again->prior_adjustment_sen);
        $this->assertSame(1000, $this->capital->balanceSen());
    }

    public function test_a_gap_above_rm1_needs_a_note_but_never_blocks(): void
    {
        $this->assertRejected('gap_note', fn () => $this->close(2026, 9, 0, cash: 101, note: null));
        $this->assertRejected('gap_note', fn () => $this->close(2026, 9, 0, cash: 101, note: '   '));

        $this->assertSame(101, $this->close(2026, 9, 0, cash: 101, note: 'transfer in transit')->gap_sen);
    }

    public function test_a_gap_of_at_most_rm1_needs_no_note(): void
    {
        $this->assertSame(100, $this->close(2026, 9, 0, cash: 100, note: null)->gap_sen);
    }

    public function test_every_active_cash_account_needs_a_balance_and_is_snapshotted_by_name(): void
    {
        $bank = CashAccount::query()->create(['name' => 'LWF Maybank']);
        CashAccount::query()->create(['name' => 'Old', 'is_active' => false]);

        $this->assertRejected('cash_balances', fn () => $this->close(2026, 9, 0));

        $close = $this->closes()->close(2026, 9, [], [...$this->cash(40), ['cash_account_id' => $bank->id, 'balance_sen' => 60]], null, $this->admin->id);

        $this->assertSame(['Held by Farres (mixed)', 'LWF Maybank'], array_column($close->cash_balances, 'name'));
        $this->assertSame(100, CashPositionService::gapSen($close->equation, 100));
    }
}
