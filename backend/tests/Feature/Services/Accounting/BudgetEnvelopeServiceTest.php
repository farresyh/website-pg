<?php

namespace Tests\Feature\Services\Accounting;

use App\Models\AdminUser;
use App\Models\BudgetEnvelope;
use App\Models\BudgetEnvelopePosting;
use App\Services\Accounting\BudgetEnvelopeService;
use App\Services\Accounting\EnvelopePostingType;
use App\Services\Accounting\ExpenseCategory;
use App\Services\Accounting\FundType;
use App\Services\Accounting\PaidFrom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADR-083 2026-10-10 addendum, decisions 2–8: one posting header with typed
 * lines. Every row of decision 3's table has its invariant pinned here.
 */
class BudgetEnvelopeServiceTest extends TestCase
{
    use RefreshDatabase;

    private BudgetEnvelopeService $service;

    private int $adminId;

    private BudgetEnvelope $rolling;

    private BudgetEnvelope $marketing;

    private BudgetEnvelope $ops;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BudgetEnvelopeService::class);
        $this->adminId = AdminUser::factory()->superAdmin()->create()->id;
        $this->rolling = BudgetEnvelope::query()->where('name', 'Capital Rolling')->firstOrFail();
        $this->marketing = BudgetEnvelope::query()->where('name', 'Marketing Budget')->firstOrFail();
        $this->ops = BudgetEnvelope::query()->where('name', 'Maintenance / Operations')->firstOrFail();
    }

    /** @param list<array{0: BudgetEnvelope, 1: int}> $lines */
    private function record(EnvelopePostingType $type, array $lines, array $extra = []): BudgetEnvelopePosting
    {
        return $this->service->post(
            type: $type,
            lines: array_map(fn (array $l) => ['budget_envelope_id' => $l[0]->id, 'amount_sen' => $l[1]], $lines),
            transactionDate: $extra['date'] ?? '2026-09-15',
            description: $extra['description'] ?? 'test',
            adminUserId: $this->adminId,
            counterparty: $extra['counterparty'] ?? null,
            fundType: $extra['fund_type'] ?? null,
            expenseCategory: $extra['expense_category'] ?? null,
        );
    }

    private function loan(int $amountSen, PaidFrom $lender = PaidFrom::Lokman): BudgetEnvelopePosting
    {
        return $this->record(EnvelopePostingType::Funding, [[$this->rolling, $amountSen]], ['counterparty' => $lender, 'fund_type' => FundType::Loan]);
    }

    private function assertRejected(callable $fn, string $key): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors(), 'rejected, but under a different key: '.json_encode($e->errors()));

            return;
        }

        $this->fail("Expected a validation error on `{$key}`.");
    }

    // ── funding ──

    /** The RM40k plan: one loan, split three ways in one posting — never 80k. */
    public function test_funding_splits_one_loan_across_envelopes_and_raises_the_loan_balance_once(): void
    {
        $posting = $this->record(EnvelopePostingType::Funding, [
            [$this->rolling, 3000000], [$this->marketing, 500000], [$this->ops, 500000],
        ], ['counterparty' => PaidFrom::Lokman, 'fund_type' => FundType::Loan]);

        $this->assertSame(4000000, $posting->amount_sen);
        $this->assertCount(3, $posting->lines);
        $this->assertSame(3000000, $this->rolling->balanceSen());
        $this->assertSame(500000, $this->marketing->balanceSen());
        $this->assertSame(500000, $this->ops->balanceSen());
        $this->assertSame(4000000, $this->service->loanBalanceSen(PaidFrom::Lokman));
    }

    public function test_share_capital_funding_does_not_count_as_a_loan(): void
    {
        $this->record(EnvelopePostingType::Funding, [[$this->rolling, 100]], ['counterparty' => PaidFrom::Farres, 'fund_type' => FundType::ShareCapital]);

        $this->assertSame(100, $this->rolling->balanceSen());
        $this->assertSame(0, $this->service->loanBalanceSen(PaidFrom::Farres));
    }

    public function test_funding_rejects_a_negative_line_a_missing_lender_or_fund_type_and_a_repeated_envelope(): void
    {
        $this->assertRejected(fn () => $this->record(EnvelopePostingType::Funding, [[$this->rolling, -100]], ['counterparty' => PaidFrom::Lokman, 'fund_type' => FundType::Loan]), 'lines');
        $this->assertRejected(fn () => $this->record(EnvelopePostingType::Funding, [[$this->rolling, 100]], ['fund_type' => FundType::Loan]), 'counterparty');
        $this->assertRejected(fn () => $this->record(EnvelopePostingType::Funding, [[$this->rolling, 100]], ['counterparty' => PaidFrom::Lokman]), 'fund_type');
        $this->assertRejected(fn () => $this->record(EnvelopePostingType::Funding, [[$this->rolling, 100], [$this->rolling, 50]], ['counterparty' => PaidFrom::Lokman, 'fund_type' => FundType::Loan]), 'lines');
    }

    /** The company account is where money sits, never a lender. */
    public function test_counterparty_must_be_a_director(): void
    {
        $this->assertRejected(fn () => $this->record(EnvelopePostingType::Funding, [[$this->rolling, 100]], ['counterparty' => PaidFrom::CompanyAccount, 'fund_type' => FundType::Loan]), 'counterparty');
    }

    // ── transfer ──

    public function test_transfer_moves_money_between_envelopes_and_nets_to_zero(): void
    {
        $this->loan(10000);

        $posting = $this->record(EnvelopePostingType::Transfer, [[$this->rolling, -4000], [$this->marketing, 4000]]);

        $this->assertSame(4000, $posting->amount_sen);
        $this->assertSame(6000, $this->rolling->balanceSen());
        $this->assertSame(4000, $this->marketing->balanceSen());
    }

    public function test_transfer_rejects_lines_that_do_not_net_to_zero_or_move_one_way(): void
    {
        $this->assertRejected(fn () => $this->record(EnvelopePostingType::Transfer, [[$this->rolling, -4000], [$this->marketing, 3000]]), 'lines');
        $this->assertRejected(fn () => $this->record(EnvelopePostingType::Transfer, [[$this->rolling, -4000]]), 'lines');
    }

    // ── expense ──

    public function test_expense_debits_exactly_one_envelope_and_needs_a_sub_category(): void
    {
        $posting = $this->record(EnvelopePostingType::Expense, [[$this->marketing, -2000]], ['expense_category' => ExpenseCategory::Advertising]);

        $this->assertSame(2000, $posting->amount_sen);
        $this->assertSame(-2000, $this->marketing->balanceSen(), 'decision 5: a negative balance is allowed');

        $this->assertRejected(fn () => $this->record(EnvelopePostingType::Expense, [[$this->marketing, -2000]]), 'expense_category');
        $this->assertRejected(fn () => $this->record(EnvelopePostingType::Expense, [[$this->marketing, -1000], [$this->ops, -1000]], ['expense_category' => ExpenseCategory::Other]), 'lines');
        $this->assertRejected(fn () => $this->record(EnvelopePostingType::Expense, [[$this->marketing, 2000]], ['expense_category' => ExpenseCategory::Other]), 'lines');
    }

    public function test_a_field_that_does_not_belong_to_the_type_is_rejected(): void
    {
        $this->assertRejected(fn () => $this->record(EnvelopePostingType::Expense, [[$this->marketing, -2000]], ['expense_category' => ExpenseCategory::Other, 'counterparty' => PaidFrom::Farres]), 'counterparty');
        $this->assertRejected(fn () => $this->record(EnvelopePostingType::Transfer, [[$this->rolling, -1], [$this->ops, 1]], ['fund_type' => FundType::Loan]), 'fund_type');
    }

    // ── director-paid expense ──

    /** Decision 8: company cash did not move, so the envelope nets to zero; the director is now owed the money. */
    public function test_director_paid_expense_nets_the_envelope_to_zero_and_raises_that_directors_loan(): void
    {
        $posting = $this->record(EnvelopePostingType::DirectorPaidExpense, [[$this->marketing, 20000], [$this->marketing, -20000]], [
            'counterparty' => PaidFrom::Farres, 'expense_category' => ExpenseCategory::Advertising,
        ]);

        $this->assertSame(20000, $posting->amount_sen);
        $this->assertSame(0, $this->marketing->balanceSen());
        $this->assertSame(20000, $this->service->loanBalanceSen(PaidFrom::Farres));
        $this->assertSame(0, $this->service->loanBalanceSen(PaidFrom::Lokman));
    }

    public function test_director_paid_expense_rejects_unbalanced_or_split_lines(): void
    {
        $extra = ['counterparty' => PaidFrom::Farres, 'expense_category' => ExpenseCategory::Other];

        $this->assertRejected(fn () => $this->record(EnvelopePostingType::DirectorPaidExpense, [[$this->marketing, 20000], [$this->marketing, -10000]], $extra), 'lines');
        $this->assertRejected(fn () => $this->record(EnvelopePostingType::DirectorPaidExpense, [[$this->marketing, 20000], [$this->ops, -20000]], $extra), 'lines');
    }

    // ── repayment ──

    public function test_repayment_reduces_the_envelope_and_the_lenders_balance(): void
    {
        $this->loan(50000);

        $this->record(EnvelopePostingType::Repayment, [[$this->rolling, -20000]], ['counterparty' => PaidFrom::Lokman]);

        $this->assertSame(30000, $this->rolling->balanceSen());
        $this->assertSame(30000, $this->service->loanBalanceSen(PaidFrom::Lokman));
    }

    /** Decision 3: overpaying a director is a real money error, blocked outright. */
    public function test_repayment_above_the_lenders_balance_is_blocked(): void
    {
        $this->loan(50000);
        $this->loan(99999, PaidFrom::Wheng);

        $this->assertRejected(fn () => $this->record(EnvelopePostingType::Repayment, [[$this->rolling, -50001]], ['counterparty' => PaidFrom::Lokman]), 'lines');

        $this->record(EnvelopePostingType::Repayment, [[$this->rolling, -50000]], ['counterparty' => PaidFrom::Lokman]);
        $this->assertSame(0, $this->service->loanBalanceSen(PaidFrom::Lokman));
    }

    // ── distribution ──

    public function test_distribution_debits_one_envelope_and_leaves_loans_alone(): void
    {
        $this->loan(10000);

        $this->record(EnvelopePostingType::Distribution, [[$this->rolling, -3000]], ['counterparty' => PaidFrom::Farres]);

        $this->assertSame(7000, $this->rolling->balanceSen());
        $this->assertSame(10000, $this->service->loanBalanceSen(PaidFrom::Lokman));
        $this->assertSame(0, $this->service->loanBalanceSen(PaidFrom::Farres));
    }

    // ── profit allocation (PR-1: still the manual monthly action) ──

    public function test_profit_allocation_is_one_posting_across_envelopes(): void
    {
        $posting = $this->record(EnvelopePostingType::ProfitAllocation, [[$this->rolling, 5000], [$this->ops, 1000]]);

        $this->assertSame(6000, $posting->amount_sen);
        $this->assertSame(5000, $this->rolling->balanceSen());
        $this->assertSame(1000, $this->ops->balanceSen());
    }

    // ── void ──

    /** Decision 2: one void reverses every line of the posting, and the original stays. */
    public function test_void_reverses_every_line_and_restores_the_loan_balance(): void
    {
        $posting = $this->record(EnvelopePostingType::Funding, [
            [$this->rolling, 3000000], [$this->marketing, 500000],
        ], ['counterparty' => PaidFrom::Lokman, 'fund_type' => FundType::Loan]);

        $reversal = $this->service->void($posting, 'typed the wrong amount', $this->adminId);

        $this->assertSame($posting->id, $reversal->reverses_posting_id);
        $this->assertSame(-3500000, $reversal->amount_sen);
        $this->assertSame('2026-09-15', $reversal->transaction_date->toDateString(), 'dated with the posting it cancels');
        $this->assertSame(0, $this->rolling->balanceSen());
        $this->assertSame(0, $this->marketing->balanceSen());
        $this->assertSame(0, $this->service->loanBalanceSen(PaidFrom::Lokman));
        $this->assertSame(3500000, $posting->fresh()->amount_sen);
    }

    public function test_a_posting_cannot_be_voided_twice_and_a_reversal_cannot_be_voided(): void
    {
        $posting = $this->loan(1000);
        $reversal = $this->service->void($posting, 'first', $this->adminId);

        $this->assertRejected(fn () => $this->service->void($posting->fresh(), 'second', $this->adminId), 'posting');
        $this->assertRejected(fn () => $this->service->void($reversal, 'undo', $this->adminId), 'posting');
    }

    /** Voiding a loan already partly repaid would leave the company owing a negative amount. */
    public function test_voiding_a_loan_that_would_leave_the_balance_negative_is_blocked(): void
    {
        $loan = $this->loan(50000);
        $this->record(EnvelopePostingType::Repayment, [[$this->rolling, -20000]], ['counterparty' => PaidFrom::Lokman]);

        $this->assertRejected(fn () => $this->service->void($loan, 'mistake', $this->adminId), 'posting');
    }

    public function test_voiding_a_repayment_restores_the_loan_balance(): void
    {
        $this->loan(50000);
        $repayment = $this->record(EnvelopePostingType::Repayment, [[$this->rolling, -20000]], ['counterparty' => PaidFrom::Lokman]);

        $this->service->void($repayment, 'bank bounced it', $this->adminId);

        $this->assertSame(50000, $this->service->loanBalanceSen(PaidFrom::Lokman));
        $this->assertSame(50000, $this->rolling->balanceSen());
    }

    // ── envelopes ──

    public function test_a_new_posting_cannot_touch_an_archived_envelope(): void
    {
        $this->ops->update(['is_active' => false]);

        $this->assertRejected(fn () => $this->record(EnvelopePostingType::Expense, [[$this->ops, -100]], ['expense_category' => ExpenseCategory::Other]), 'lines');
    }

    /** Both header and lines are append-only at the model layer. */
    public function test_postings_and_lines_cannot_be_updated_or_deleted(): void
    {
        $posting = $this->loan(1000);

        try {
            $posting->update(['description' => 'edited']);
            $this->fail('posting update should throw');
        } catch (\LogicException) {
        }

        $this->expectException(\LogicException::class);
        $posting->lines->first()->delete();
    }

    /** Decision 7: per-director balances in one call, every director listed. */
    public function test_loan_balances_lists_every_director(): void
    {
        $this->loan(4000000);
        $this->record(EnvelopePostingType::DirectorPaidExpense, [[$this->marketing, 200], [$this->marketing, -200]], [
            'counterparty' => PaidFrom::Farres, 'expense_category' => ExpenseCategory::Software,
        ]);

        $this->assertSame(['farres' => 200, 'lokman' => 4000000, 'wheng' => 0], $this->service->loanBalances());
    }
}
