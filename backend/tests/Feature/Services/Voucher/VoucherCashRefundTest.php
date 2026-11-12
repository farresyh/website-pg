<?php

namespace Tests\Feature\Services\Voucher;

use App\Models\AccountingPeriodClose;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Voucher;
use App\Models\VoucherCashRefund;
use App\Services\Accounting\CashPositionService;
use App\Services\Accounting\MonthlyAccountingSummaryService;
use App\Services\Accounting\TransactionRegisterService;
use App\Services\Fulfillment\OrderSettlementService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Voucher\InvalidVoucherException;
use App\Services\Voucher\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-004 2026-11-12 addendum: a customer-cash voucher's balance paid out by
 * bank transfer. The voucher stays the compensation record; the payout
 * closes it, settles the liability with cash and never moves profit.
 */
class VoucherCashRefundTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.accounting_disk'));
        $this->travelTo(now('Asia/Kuala_Lumpur')->startOfMonth()->addDays(14)->setTime(12, 0));
        $this->admin = AdminUser::factory()->superAdmin()->create();
        $this->actingAsSteppedUp($this->admin);
    }

    private function failedOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'placed_via' => 'storefront', 'affiliate_id' => $this->primaryAffiliate()->id, 'order_number' => 'PG-'.strtoupper(uniqid()),
            'customer_email' => 'buyer@example.com', 'customer_phone' => '60123456789', 'player_id' => '123456',
            'cost_price' => 900, 'standard_selling_price' => 1000, 'selling_price' => 1000, 'transaction_fee' => 100, 'final_amount' => 1100,
            'platform_profit' => 100, 'affiliate_profit' => 0, 'paid_at' => now(),
            'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value,
        ], $overrides));
    }

    /** The real compensation path: a failed order settled with a voucher (RM10.00, the fee is never refunded). */
    private function compensationVoucher(?Order $order = null): Voucher
    {
        return app(OrderSettlementService::class)->settle($order ?? $this->failedOrder(), $this->admin->id)->voucher;
    }

    private function giftVoucher(int $amount = 1000): Voucher
    {
        return app(VoucherService::class)->issue('buyer@example.com', $amount, 'Goodwill', null, $this->admin->id, null, $this->primaryAffiliate()->id);
    }

    private function refund(Voucher $voucher, array $overrides = []): TestResponse
    {
        return $this->post("/api/vouchers/{$voucher->id}/cash-refund", array_merge([
            'transferred_on' => now('Asia/Kuala_Lumpur')->toDateString(),
            'bank_name' => 'Maybank',
            'bank_account_no' => '1122 3344 5566',
            'bank_account_holder' => 'Ali bin Abu',
            'bank_reference' => 'MBB-778899',
            'proof' => UploadedFile::fake()->image('transfer.png'),
            'reason' => 'Customer plays no other game',
        ], $overrides), ['Accept' => 'application/json']);
    }

    public function test_the_balance_is_paid_out_and_the_voucher_is_closed(): void
    {
        $voucher = $this->compensationVoucher();
        $this->assertSame(Voucher::SOURCE_CUSTOMER_CASH, $voucher->source);
        $this->assertSame(1000, $voucher->amount);

        // A typed amount is ignored: the payout is always the balance.
        $this->refund($voucher, ['amount_sen' => 999_999])->assertCreated()->assertJsonPath('cash_refund.amount_sen', 1000);

        $voucher->refresh();
        $this->assertSame('refunded', $voucher->status);
        $this->assertSame(0, $voucher->remaining);
        $refund = VoucherCashRefund::query()->sole();
        $this->assertSame(1000, $refund->amount_sen);
        $this->assertSame('Ali bin Abu', $refund->bank_account_holder);
        $this->assertSame($this->admin->id, $refund->created_by);
        $this->assertStringStartsWith('accounting/voucher-cash-refunds/'.now('Asia/Kuala_Lumpur')->format('Y/m').'/', $refund->proof_path);
        Storage::disk(config('filesystems.accounting_disk'))->assertExists($refund->proof_path);

        // The order is still compensated by its voucher; nothing else about it changes.
        $this->assertTrue($voucher->sourceOrder->isAlreadyCompensated());
        $this->assertSame(1000, $voucher->sourceOrder->cashCompensationSen());

        // A refunded voucher can't be spent.
        $this->expectException(InvalidVoucherException::class);
        app(VoucherService::class)->preview($voucher->code, 'buyer@example.com', null, 500, $this->primaryAffiliate()->id);
    }

    public function test_a_partly_spent_voucher_refunds_only_what_is_left(): void
    {
        $voucher = $this->compensationVoucher();
        $order = $this->failedOrder(['delivery_status' => DeliveryStatus::NotStarted->value, 'payment_status' => PaymentStatus::Pending->value, 'voucher_id' => $voucher->id, 'voucher_discount' => 600]);
        app(VoucherService::class)->redeem($voucher->id, $order->id, 600, 'buyer@example.com', null);
        app(VoucherService::class)->commit($order->id);

        $this->refund($voucher)->assertCreated()->assertJsonPath('cash_refund.amount_sen', 400);
    }

    public function test_a_voucher_an_order_is_still_using_is_refused(): void
    {
        $voucher = $this->compensationVoucher();
        $order = $this->failedOrder(['delivery_status' => DeliveryStatus::NotStarted->value, 'payment_status' => PaymentStatus::Pending->value, 'voucher_id' => $voucher->id, 'voucher_discount' => 600]);
        app(VoucherService::class)->redeem($voucher->id, $order->id, 600, 'buyer@example.com', null);

        $this->refund($voucher)->assertUnprocessable()->assertJsonValidationErrors('voucher');
        $this->assertSame(400, $voucher->fresh()->remaining);
        $this->assertSame(0, VoucherCashRefund::query()->count());
    }

    public function test_gift_credit_is_never_refunded_in_cash(): void
    {
        $gift = $this->giftVoucher();
        $this->assertSame(Voucher::SOURCE_GIFT, $gift->source);

        $this->refund($gift)->assertUnprocessable()->assertJsonValidationErrors('voucher');
        $this->assertSame('active', $gift->fresh()->status);
    }

    public function test_a_sandbox_orders_voucher_is_not_customer_cash(): void
    {
        $voucher = app(VoucherService::class)->issue('buyer@example.com', 1000, 'test', null, $this->admin->id, null, $this->primaryAffiliate()->id, orderId: $this->failedOrder(['is_test' => true])->id);

        $this->assertSame(Voucher::SOURCE_GIFT, $voucher->source);
    }

    public function test_a_voucher_that_is_not_active_or_has_no_balance_is_refused(): void
    {
        $exhausted = $this->compensationVoucher();
        $exhausted->update(['status' => 'exhausted', 'remaining' => 0]);
        $expired = $this->compensationVoucher();
        $expired->update(['expires_at' => now()->subDay()]);

        $this->refund($exhausted)->assertUnprocessable()->assertJsonValidationErrors('voucher');
        $this->refund($expired)->assertUnprocessable()->assertJsonValidationErrors('voucher');
        $this->assertSame(0, VoucherCashRefund::query()->count());
    }

    public function test_a_voucher_is_refunded_once(): void
    {
        $voucher = $this->compensationVoucher();

        $this->refund($voucher)->assertCreated();
        $this->refund($voucher)->assertUnprocessable()->assertJsonValidationErrors('voucher');

        $this->assertSame(1, VoucherCashRefund::query()->count());
        // The refused second attempt left no file behind.
        $this->assertCount(1, Storage::disk(config('filesystems.accounting_disk'))->allFiles());
    }

    /** ADR-127 decision 11: a late Success leaves the order Failed, so the recorded result is what is checked. */
    public function test_a_late_supplier_result_on_the_source_order_blocks_the_refund(): void
    {
        $order = $this->failedOrder();
        $voucher = $this->compensationVoucher($order);
        $order->update(['supplier_response' => ['late_supplier_result' => ['status' => 'Sukses']]]);
        $this->assertSame(DeliveryStatus::Failed, $order->fresh()->delivery_status);

        $this->refund($voucher)->assertUnprocessable()->assertJsonValidationErrors('voucher');
        $this->assertStringContainsString($order->order_number, $this->refund($voucher)->json('errors.voucher.0'));
    }

    public function test_a_merged_voucher_is_customer_cash_and_checks_every_source_order(): void
    {
        $first = $this->failedOrder();
        $second = $this->failedOrder();
        $merged = app(VoucherService::class)->merge([$this->compensationVoucher($first)->id, $this->compensationVoucher($second)->id], 'Same customer', $this->admin->id, null);
        $this->assertSame(Voucher::SOURCE_CUSTOMER_CASH, $merged->source);
        $this->assertSame([$first->id, $second->id], app(VoucherService::class)->sourceOrderIds($merged));

        $second->update(['supplier_response' => ['late_supplier_result' => ['status' => 'Sukses']]]);
        $this->refund($merged)->assertUnprocessable()->assertJsonValidationErrors('voucher');

        $second->update(['supplier_response' => null]);
        $this->refund($merged)->assertCreated()->assertJsonPath('cash_refund.amount_sen', 2000);
    }

    public function test_customer_cash_and_gift_credit_are_never_merged(): void
    {
        $cash = $this->compensationVoucher();
        $gift = $this->giftVoucher();

        $this->expectException(InvalidVoucherException::class);
        app(VoucherService::class)->merge([$cash->id, $gift->id], 'Same customer', $this->admin->id, null);
    }

    public function test_the_transfer_date_must_be_today_or_earlier_this_month_in_an_open_month(): void
    {
        $voucher = $this->compensationVoucher();
        $today = now('Asia/Kuala_Lumpur');

        $this->refund($voucher, ['transferred_on' => $today->copy()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('transferred_on');
        $this->refund($voucher, ['transferred_on' => $today->copy()->startOfMonth()->subDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('transferred_on');

        AccountingPeriodClose::query()->create([
            'period_month' => $today->copy()->startOfMonth()->toDateString(),
            'operating_profit_sen' => 0, 'prior_adjustment_sen' => 0, 'allocated_sen' => 0,
            'profit_lines' => [], 'cash_balances' => [], 'equation' => [], 'gap_sen' => 0,
            'closed_by' => $this->admin->id,
        ]);
        $this->refund($voucher)->assertUnprocessable()->assertJsonValidationErrors('transferred_on');

        $this->assertSame('active', $voucher->fresh()->status);
        $this->assertSame([], Storage::disk(config('filesystems.accounting_disk'))->allFiles());

        $this->refund($voucher, ['transferred_on' => $today->copy()->subDays(3)->toDateString()])->assertUnprocessable();
    }

    public function test_an_earlier_day_this_month_is_accepted(): void
    {
        $this->refund($this->compensationVoucher(), ['transferred_on' => now('Asia/Kuala_Lumpur')->subDays(3)->toDateString()])->assertCreated();
    }

    public function test_every_field_and_the_proof_are_required(): void
    {
        $voucher = $this->compensationVoucher();

        $this->refund($voucher, ['proof' => null, 'bank_name' => '', 'bank_account_no' => 'abc', 'bank_account_holder' => '', 'bank_reference' => '', 'reason' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['proof', 'bank_name', 'bank_account_no', 'bank_account_holder', 'bank_reference', 'reason']);
    }

    public function test_only_a_stepped_up_super_admin_can_refund(): void
    {
        $voucher = $this->compensationVoucher();

        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        $this->refund($voucher)->assertForbidden();

        $this->flushSession();
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create(['mfa_required' => true, 'mfa_enabled' => true]));
        $this->refund($voucher)->assertStatus(403);

        $this->assertSame(0, VoucherCashRefund::query()->count());
    }

    /** Decision 7: a liability settled with cash. No income, no breakage, no operating profit; the claim falls with the bank. */
    public function test_a_refund_never_moves_profit_and_leaves_the_claim_with_the_cash(): void
    {
        $now = now('Asia/Kuala_Lumpur');
        $summary = fn () => app(MonthlyAccountingSummaryService::class)->forPeriod($now->year, $now->month);
        $claim = fn () => app(CashPositionService::class)->asOf($now->year, $now->month)['claims']['vouchers_outstanding_sen'];

        $voucher = $this->compensationVoucher();
        $before = $summary();
        $this->assertSame(1000, $claim());

        $this->refund($voucher)->assertCreated();

        $after = $summary();
        $this->assertSame($before['operating_profit_sen'], $after['operating_profit_sen']);
        $this->assertSame(0, $after['voucher_breakage_sen']);
        $this->assertSame(1000, $after['failed_order_credit_refunded_sen']);
        $this->assertSame(0, $claim(), 'the claim fell by the same RM10.00 that left the bank');

        $rows = array_values(array_filter(app(TransactionRegisterService::class)->rows(null, null), fn (array $r) => $r['type'] === 'voucher_cash_refund'));
        $this->assertCount(1, $rows);
        $this->assertSame(-1000, $rows[0]['net_sen']);
        $this->assertSame($voucher->code, $rows[0]['reference']);
    }

    public function test_the_proof_can_be_downloaded(): void
    {
        $voucher = $this->compensationVoucher();
        $this->refund($voucher)->assertCreated();

        $this->get("/api/vouchers/{$voucher->id}/cash-refund/proof")->assertOk();
        $this->getJson("/api/vouchers/{$this->compensationVoucher()->id}/cash-refund/proof")->assertNotFound();
    }

    public function test_the_detail_shows_the_source_orders_and_the_refund(): void
    {
        $order = $this->failedOrder();
        $voucher = $this->compensationVoucher($order);

        $this->getJson("/api/vouchers/{$voucher->id}")
            ->assertOk()
            ->assertJsonPath('voucher.source', Voucher::SOURCE_CUSTOMER_CASH)
            ->assertJsonPath('refund_source_orders.0.order_number', $order->order_number)
            ->assertJsonPath('refund_source_orders.0.late_supplier_result', null);

        $this->refund($voucher)->assertCreated();

        $response = $this->getJson("/api/vouchers/{$voucher->id}")->assertOk()
            ->assertJsonPath('voucher.display_status', 'refunded')
            ->assertJsonPath('voucher.cash_refund.amount_sen', 1000)
            ->assertJsonPath('voucher.cash_refund.creator.name', $this->admin->name);
        $this->assertArrayNotHasKey('proof_path', $response->json('voucher.cash_refund'));
    }
}
