<?php

namespace Tests\Feature\Services\Accounting;

use App\Models\AccountingPeriodClose;
use App\Models\AdminUser;
use App\Models\BudgetEnvelope;
use App\Models\Supplier;
use App\Models\SupplierTransfer;
use App\Services\Accounting\BudgetEnvelopeService;
use App\Services\Accounting\EnvelopePostingType;
use App\Services\Accounting\ExpenseCategory;
use App\Services\Accounting\SupplierFundingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADR-127 decision 4: every writer that takes an accounting date from a
 * person refuses a date in a closed month. This file is the list of those
 * writers; a new one adds its case here.
 */
class ClosedPeriodGuardTest extends TestCase
{
    use RefreshDatabase;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 04:00:00');
        $this->adminId = AdminUser::factory()->superAdmin()->create()->id;
        AccountingPeriodClose::query()->forceCreate(['period_month' => '2026-09-01', 'operating_profit_sen' => 0, 'prior_adjustment_sen' => 0, 'allocated_sen' => 0, 'profit_lines' => [], 'cash_balances' => [], 'equation' => [], 'gap_sen' => 0, 'closed_by' => $this->adminId]);
    }

    public function test_an_envelope_posting_dated_in_a_closed_month_is_refused_and_an_open_one_is_not(): void
    {
        $this->assertRefused(fn () => $this->expense('2026-09-30'), 'transaction_date');
        $this->assertSame('2026-10-01', $this->expense('2026-10-01')->transaction_date->toDateString());
    }

    public function test_voiding_a_posting_from_a_closed_month_is_refused(): void
    {
        $this->travelTo('2026-09-20 04:00:00');
        AccountingPeriodClose::query()->delete();
        $posting = $this->expense('2026-09-15');
        $this->closeSeptember();
        $this->travelTo('2026-10-20 04:00:00');

        $this->assertRefused(fn () => app(BudgetEnvelopeService::class)->void($posting, 'typo', $this->adminId), 'posting');
    }

    public function test_a_supplier_transfer_dated_in_a_closed_month_is_refused(): void
    {
        $this->assertRefused(fn () => $this->transfer('2026-09-30'), 'transferred_on');
        $this->assertSame('2026-10-01', $this->transfer('2026-10-01')->transferred_on->toDateString());
    }

    public function test_a_transfer_correction_or_void_that_touches_a_closed_month_is_refused(): void
    {
        $open = $this->transfer('2026-10-02');
        $this->assertRefused(fn () => app(SupplierFundingService::class)->recordCorrection($open, ['transferred_on' => '2026-09-29'], 'wrong day', $this->adminId), 'transferred_on');

        AccountingPeriodClose::query()->delete();
        $september = $this->transfer('2026-09-25');
        $this->closeSeptember();

        $this->assertRefused(fn () => app(SupplierFundingService::class)->recordCorrection($september, ['fee_myr' => 0], 'fee', $this->adminId), 'transferred_on');
        $this->assertRefused(fn () => app(SupplierFundingService::class)->voidTransfer($september, 'never arrived', $this->adminId), 'transfer');
    }

    private function closeSeptember(): void
    {
        AccountingPeriodClose::query()->forceCreate(['period_month' => '2026-09-01', 'operating_profit_sen' => 0, 'prior_adjustment_sen' => 0, 'allocated_sen' => 0, 'profit_lines' => [], 'cash_balances' => [], 'equation' => [], 'gap_sen' => 0, 'closed_by' => $this->adminId]);
    }

    private function expense(string $date)
    {
        $ops = BudgetEnvelope::query()->where('name', 'Maintenance / Operations')->firstOrFail();

        return app(BudgetEnvelopeService::class)->post(
            type: EnvelopePostingType::Expense,
            lines: [['budget_envelope_id' => $ops->id, 'amount_sen' => -1000]],
            transactionDate: $date,
            description: 'hosting',
            adminUserId: $this->adminId,
            expenseCategory: ExpenseCategory::cases()[0],
        );
    }

    private function transfer(string $date): SupplierTransfer
    {
        $supplier = Supplier::query()->firstOrCreate(['slug' => 'guard-supplier'], ['name' => 'Guard Supplier', 'driver' => 'guard-supplier', 'api_config' => [], 'currency' => 'MYR']);

        return app(SupplierFundingService::class)->recordTransfer($supplier, $date, 'bank', 10000, 0, 'MYR', '100.0000', null, null, null, $this->adminId);
    }

    private function assertRefused(callable $fn, string $key): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors(), json_encode($e->errors()));

            return;
        }

        $this->fail("Expected a closed-month refusal on `{$key}`.");
    }
}
