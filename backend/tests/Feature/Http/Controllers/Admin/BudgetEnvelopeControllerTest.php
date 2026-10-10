<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\BudgetEnvelope;
use App\Models\LedgerEntry;
use App\Services\Ledger\LedgerOwnerType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-083 Envelope Ledger, as reshaped by the 2026-10-10 addendum: one
 * posting header with typed lines. The invariants live in
 * `BudgetEnvelopeServiceTest`; this covers the HTTP surface.
 */
class BudgetEnvelopeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actAsSuperAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->superAdmin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function envelope(string $name): BudgetEnvelope
    {
        return BudgetEnvelope::query()->where('name', $name)->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function postExpense(BudgetEnvelope $envelope, int $sen, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/accounting/envelope-postings', [
            'type' => 'expense',
            'transaction_date' => '2026-09-20',
            'description' => 'Tune Talk customer-service number',
            'expense_category' => 'software',
            'lines' => [['budget_envelope_id' => $envelope->id, 'amount_sen' => -$sen]],
            ...$overrides,
        ]);
    }

    private function postLoan(BudgetEnvelope $envelope, int $sen, string $date = '2026-09-15'): TestResponse
    {
        return $this->postJson('/api/accounting/envelope-postings', [
            'type' => 'funding',
            'fund_type' => 'loan',
            'counterparty' => 'lokman',
            'transaction_date' => $date,
            'description' => 'Pinjaman Lokman',
            'lines' => [['budget_envelope_id' => $envelope->id, 'amount_sen' => $sen]],
        ]);
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/accounting/envelopes')->assertForbidden();
        $this->postJson('/api/accounting/envelope-postings', [])->assertForbidden();
    }

    public function test_index_lists_envelopes_options_and_loan_balances(): void
    {
        $this->actAsSuperAdmin();
        $this->postLoan($this->envelope('Capital Rolling'), 50000)->assertCreated();

        $response = $this->getJson('/api/accounting/envelopes')->assertOk();

        $this->assertSame(50000, collect($response->json('envelopes'))->firstWhere('name', 'Capital Rolling')['balance_sen']);
        $this->assertSame(['funding', 'transfer', 'expense', 'director_paid_expense', 'repayment', 'distribution'], collect($response->json('posting_types'))->pluck('value')->all(), 'profit_allocation is written by the allocate action only');
        $this->assertSame(['farres', 'lokman', 'wheng'], collect($response->json('directors'))->pluck('value')->all());
        $this->assertSame(['loan', 'share_capital'], collect($response->json('fund_types'))->pluck('value')->all());
        $this->assertContains('professional_fees', collect($response->json('expense_categories'))->pluck('value')->all());
        $this->assertSame(50000, collect($response->json('loan_balances'))->firstWhere('counterparty', 'lokman')['balance_sen']);
    }

    /** ADR-083 2026-10-08 addendum: a tier fee adds to the rough estimate (kept until month close replaces it, PR-2). */
    public function test_rough_pl_estimate_adds_this_months_affiliate_tier_fees(): void
    {
        $this->actAsSuperAdmin();
        LedgerEntry::query()->create([
            'owner_type' => LedgerOwnerType::Affiliate->value, 'owner_id' => 1, 'type' => 'affiliate_tier_fee',
            'amount' => -1500, 'reference_type' => 'affiliate_subscription', 'reference_id' => 1,
        ]);

        $response = $this->getJson('/api/accounting/envelopes')->assertOk();

        $this->assertSame(1500, $response->json('current_month_rough_pl_estimate_sen'));
    }

    public function test_can_create_and_rename_an_envelope_and_names_stay_unique(): void
    {
        $this->actAsSuperAdmin();

        $id = $this->postJson('/api/accounting/envelopes', ['name' => 'Staff Bonus'])->assertCreated()->json('envelope.id');
        $this->postJson('/api/accounting/envelopes', ['name' => 'Staff Bonus'])->assertUnprocessable();
        $this->patchJson("/api/accounting/envelopes/{$id}", ['name' => 'Bonus Pool'])->assertOk()->assertJsonPath('envelope.name', 'Bonus Pool');
        $this->patchJson("/api/accounting/envelopes/{$id}", ['name' => 'Capital Rolling'])->assertUnprocessable();
    }

    /** Decision 6: archiving an envelope that still holds money would hide that money from the grid. */
    public function test_an_envelope_can_be_archived_only_at_a_zero_balance(): void
    {
        $this->actAsSuperAdmin();
        $envelope = $this->envelope('Company Savings');
        $this->postLoan($envelope, 5000)->assertCreated();

        $this->patchJson("/api/accounting/envelopes/{$envelope->id}", ['is_active' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('is_active');

        $this->postJson('/api/accounting/envelope-postings', [
            'type' => 'transfer', 'transaction_date' => '2026-09-16', 'description' => 'empty it',
            'lines' => [
                ['budget_envelope_id' => $envelope->id, 'amount_sen' => -5000],
                ['budget_envelope_id' => $this->envelope('Capital Rolling')->id, 'amount_sen' => 5000],
            ],
        ])->assertCreated();

        $this->patchJson("/api/accounting/envelopes/{$envelope->id}", ['is_active' => false])->assertOk()->assertJsonPath('envelope.is_active', false);
        $this->patchJson("/api/accounting/envelopes/{$envelope->id}", ['is_active' => true])->assertOk()->assertJsonPath('envelope.is_active', true);
    }

    public function test_storing_a_posting_returns_it_with_its_lines(): void
    {
        $this->actAsSuperAdmin();
        $marketing = $this->envelope('Marketing Budget');

        $response = $this->postExpense($marketing, 2000, ['reference_no' => 'TT-123'])->assertCreated();

        $response->assertJsonPath('posting.type', 'expense');
        $response->assertJsonPath('posting.amount_sen', 2000);
        $response->assertJsonPath('posting.transaction_date', '2026-09-20');
        $response->assertJsonPath('posting.reference_no', 'TT-123');
        $response->assertJsonPath('posting.lines.0.amount_sen', -2000);
        $this->assertSame(-2000, $marketing->fresh()->balanceSen());
    }

    public function test_store_validates_shape_and_surfaces_service_invariants_as_422(): void
    {
        $this->actAsSuperAdmin();
        $marketing = $this->envelope('Marketing Budget');

        $this->postJson('/api/accounting/envelope-postings', ['type' => 'expense'])
            ->assertUnprocessable()->assertJsonValidationErrors(['transaction_date', 'description', 'lines']);
        $this->postExpense($marketing, 2000, ['transaction_date' => now('Asia/Kuala_Lumpur')->addDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('transaction_date');
        $this->postExpense($marketing, 2000, ['type' => 'adjustment'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postExpense($marketing, 2000, ['counterparty' => 'company_account'])
            ->assertUnprocessable()->assertJsonValidationErrors('counterparty');
        // Service invariant: an expense line must be negative.
        $this->postExpense($marketing, -2000)
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
    }

    public function test_a_receipt_is_stored_privately_on_the_posting_and_downloadable(): void
    {
        Storage::fake(config('filesystems.accounting_disk'));
        $this->actAsSuperAdmin();

        $response = $this->post('/api/accounting/envelope-postings', [
            'type' => 'expense',
            'transaction_date' => '2026-09-26',
            'description' => 'Domain pekangame.com',
            'expense_category' => 'software',
            'lines' => [['budget_envelope_id' => $this->envelope('Maintenance / Operations')->id, 'amount_sen' => -4651]],
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $postingId = $response->json('posting.id');
        Storage::disk(config('filesystems.accounting_disk'))->assertExists($response->json('posting.receipt_path'));
        $this->get("/api/accounting/envelope-postings/{$postingId}/receipt")->assertOk();
    }

    /** One void reverses the whole posting; the listing shows the original as voided, never deleted, and the pair nets to zero in its own period. */
    public function test_void_reverses_the_posting_and_the_entries_listing_keeps_both(): void
    {
        $this->actAsSuperAdmin();
        $rolling = $this->envelope('Capital Rolling');
        $postingId = $this->postLoan($rolling, 100000, '2026-09-15')->assertCreated()->json('posting.id');

        $this->postJson("/api/accounting/envelope-postings/{$postingId}/void", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/accounting/envelope-postings/{$postingId}/void", ['reason' => 'wrong amount'])
            ->assertCreated()
            ->assertJsonPath('reversal.reverses_posting_id', $postingId)
            ->assertJsonPath('reversal.amount_sen', -100000);
        $this->postJson("/api/accounting/envelope-postings/{$postingId}/void", ['reason' => 'again'])->assertUnprocessable();

        $september = collect($this->getJson("/api/accounting/envelopes/{$rolling->id}/entries?from=2026-09-15&to=2026-09-15")->assertOk()->json('entries'));
        $this->assertCount(2, $september);
        $this->assertSame(0, $september->sum('amount_sen'));
        $original = $september->firstWhere('posting_id', $postingId);
        $this->assertTrue($original['is_voided']);
        $this->assertSame('Lokman (personal)', $original['counterparty_label']);
        $this->assertSame('Loan', $original['fund_type_label']);

        $today = now('Asia/Kuala_Lumpur')->toDateString();
        $this->assertCount(0, $this->getJson("/api/accounting/envelopes/{$rolling->id}/entries?from={$today}&to={$today}")->json('entries'));
    }

    public function test_allocate_monthly_profit_writes_one_posting_across_envelopes(): void
    {
        $this->actAsSuperAdmin();
        $rolling = $this->envelope('Capital Rolling');
        $marketing = $this->envelope('Marketing Budget');

        $response = $this->postJson('/api/accounting/envelopes/allocate-monthly-profit', [
            'period_label' => 'September 2026',
            'allocations' => [
                ['budget_envelope_id' => $rolling->id, 'amount_sen' => 500000],
                ['budget_envelope_id' => $marketing->id, 'amount_sen' => 200000],
            ],
        ])->assertCreated();

        $response->assertJsonPath('posting.type', 'profit_allocation');
        $response->assertJsonPath('posting.amount_sen', 700000);
        $this->assertCount(2, $response->json('posting.lines'));
        $this->assertSame(500000, $rolling->fresh()->balanceSen());
        $this->assertSame(200000, $marketing->fresh()->balanceSen());
    }

    /** The export keeps both a voided posting's lines and their reversal — never a silently vanished original. */
    public function test_export_shows_each_line_with_its_posting_and_status(): void
    {
        $this->actAsSuperAdmin();
        $postingId = $this->postLoan($this->envelope('Capital Rolling'), 100000)->assertCreated()->json('posting.id');
        $this->postJson("/api/accounting/envelope-postings/{$postingId}/void", ['reason' => 'export test'])->assertCreated();

        $response = $this->get('/api/accounting/envelopes/export')->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $rows = array_map(fn (string $line) => str_getcsv($line), array_values(array_filter(explode("\n", $response->streamedContent()))));
        $this->assertSame(['Posting', 'Transaction Date', 'Recorded At', 'Type', 'Envelope', 'Amount (RM)', 'Description', 'Counterparty', 'Fund Type', 'Expense Category', 'Reference', 'Recorded By', 'Has Receipt', 'Status'], $rows[0]);

        $original = collect($rows)->first(fn ($r) => $r[0] === (string) $postingId);
        $reversal = collect($rows)->first(fn ($r) => str_contains($r[6] ?? '', "reversing posting #{$postingId}"));
        $this->assertSame('1000.00', $original[5]);
        $this->assertSame('Voided', $original[13]);
        $this->assertSame('-1000.00', $reversal[5]);
        $this->assertSame('Void reversal', $reversal[13]);
    }
}
