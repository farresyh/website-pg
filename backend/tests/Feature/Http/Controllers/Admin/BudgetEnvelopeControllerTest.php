<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\BudgetEnvelope;
use App\Models\BudgetEnvelopeEntry;
use App\Services\Accounting\BudgetEnvelopeEntryCategory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-083 2026-09-28 "Envelope Ledger" addendum — discretionary,
 * director-controlled budget tracking, added by re-grilling ADR-083
 * decision 11. The 4 starter envelopes are seeded by the migration
 * itself (Capital Rolling/Marketing Budget/Maintenance/Company Savings).
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

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/accounting/envelopes')->assertForbidden();
    }

    public function test_index_lists_the_four_seeded_envelopes_with_zero_balance(): void
    {
        $this->actAsSuperAdmin();

        $response = $this->getJson('/api/accounting/envelopes')->assertOk();

        $names = collect($response->json('envelopes'))->pluck('name');
        $this->assertTrue($names->contains('Capital Rolling'));
        $this->assertTrue($names->contains('Marketing Budget'));
        $this->assertTrue($names->contains('Maintenance / Operations'));
        $this->assertTrue($names->contains('Company Savings'));
        $this->assertSame(0, collect($response->json('envelopes'))->firstWhere('name', 'Capital Rolling')['balance_sen']);
        $this->assertArrayHasKey('current_month_summary', $response->json());
    }

    /** Adjustment is the void mechanism's own internal tag — never offered as something an admin picks manually. */
    public function test_index_excludes_adjustment_from_the_pickable_categories(): void
    {
        $this->actAsSuperAdmin();

        $response = $this->getJson('/api/accounting/envelopes')->assertOk();

        $values = collect($response->json('categories'))->pluck('value');
        $this->assertFalse($values->contains(BudgetEnvelopeEntryCategory::Adjustment->value));
        $this->assertTrue($values->contains(BudgetEnvelopeEntryCategory::OpexSalary->value));
        $this->assertTrue($values->contains(BudgetEnvelopeEntryCategory::OpexProfessionalFees->value));
    }

    public function test_can_create_a_new_envelope(): void
    {
        $this->actAsSuperAdmin();

        $response = $this->postJson('/api/accounting/envelopes', ['name' => 'Staff Bonus'])->assertCreated();

        $this->assertSame('Staff Bonus', $response->json('envelope.name'));
        $this->assertDatabaseHas('budget_envelopes', ['name' => 'Staff Bonus']);
    }

    public function test_cannot_create_a_duplicate_envelope_name(): void
    {
        $this->actAsSuperAdmin();
        BudgetEnvelope::query()->create(['name' => 'Staff Bonus']);

        $this->postJson('/api/accounting/envelopes', ['name' => 'Staff Bonus'])->assertUnprocessable();
    }

    public function test_can_rename_an_envelope(): void
    {
        $this->actAsSuperAdmin();
        $envelope = BudgetEnvelope::query()->create(['name' => 'Typo Nmae']);

        $response = $this->patchJson("/api/accounting/envelopes/{$envelope->id}", ['name' => 'Correct Name'])->assertOk();

        $this->assertSame('Correct Name', $response->json('envelope.name'));
        $this->assertDatabaseHas('budget_envelopes', ['id' => $envelope->id, 'name' => 'Correct Name']);
    }

    public function test_renaming_to_an_existing_name_is_rejected(): void
    {
        $this->actAsSuperAdmin();
        BudgetEnvelope::query()->create(['name' => 'Taken Name']);
        $envelope = BudgetEnvelope::query()->create(['name' => 'Other Name']);

        $this->patchJson("/api/accounting/envelopes/{$envelope->id}", ['name' => 'Taken Name'])->assertUnprocessable();
    }

    /** Never a hard delete — archiving hides it from the default index filter (frontend concern) but the row and its history stay fully intact. */
    public function test_can_archive_and_reactivate_an_envelope(): void
    {
        $this->actAsSuperAdmin();
        $envelope = BudgetEnvelope::query()->where('name', 'Capital Rolling')->firstOrFail();
        $this->postJson("/api/accounting/envelopes/{$envelope->id}/entries", [
            'category' => BudgetEnvelopeEntryCategory::CapitalInjection->value, 'amount_sen' => 5000, 'description' => 'ARCHIVE-TEST',
        ])->assertCreated();

        $archived = $this->patchJson("/api/accounting/envelopes/{$envelope->id}", ['is_active' => false])->assertOk();
        $this->assertFalse($archived->json('envelope.is_active'));

        // History and balance survive archiving untouched.
        $entries = $this->getJson("/api/accounting/envelopes/{$envelope->id}/entries")->assertOk()->json('entries');
        $this->assertNotNull(collect($entries)->firstWhere('description', 'ARCHIVE-TEST'));
        $this->assertSame(5000, $envelope->fresh()->balanceSen());

        $reactivated = $this->patchJson("/api/accounting/envelopes/{$envelope->id}", ['is_active' => true])->assertOk();
        $this->assertTrue($reactivated->json('envelope.is_active'));
    }

    public function test_index_includes_a_rough_unaudited_pl_estimate(): void
    {
        $this->actAsSuperAdmin();

        $response = $this->getJson('/api/accounting/envelopes')->assertOk();

        $this->assertArrayHasKey('current_month_rough_pl_estimate_sen', $response->json());
        $this->assertIsInt($response->json('current_month_rough_pl_estimate_sen'));
        // No orders/memberships/vouchers recorded this month in this test — the estimate must be exactly 0, not null/missing.
        $this->assertSame(0, $response->json('current_month_rough_pl_estimate_sen'));
    }

    /** CapitalInjection's typical sign is positive — the request sends a plain magnitude, the controller applies the sign. */
    public function test_recording_a_capital_injection_credits_the_envelope(): void
    {
        $this->actAsSuperAdmin();
        $envelope = BudgetEnvelope::query()->where('name', 'Capital Rolling')->firstOrFail();

        $response = $this->postJson("/api/accounting/envelopes/{$envelope->id}/entries", [
            'category' => BudgetEnvelopeEntryCategory::CapitalInjection->value,
            'amount_sen' => 3000000,
            'description' => 'Modal Lokman untuk rolling capital',
        ])->assertCreated();

        $this->assertSame(3000000, $response->json('balance_sen'));
        $this->assertSame(3000000, $envelope->fresh()->balanceSen());
    }

    /** 2026-09-30 addendum — Bucket C decision 3: date/paid-from/reference are all optional and stored as given. */
    public function test_recording_an_entry_with_transaction_date_paid_from_and_reference(): void
    {
        $this->actAsSuperAdmin();
        $envelope = BudgetEnvelope::query()->where('name', 'Capital Rolling')->firstOrFail();

        $response = $this->postJson("/api/accounting/envelopes/{$envelope->id}/entries", [
            'category' => BudgetEnvelopeEntryCategory::CapitalInjection->value,
            'amount_sen' => 3000000,
            'transaction_date' => '2026-09-15',
            'description' => 'Modal Lokman untuk rolling capital',
            'paid_from' => 'luqman',
            'reference_no' => 'BANK-REF-42',
        ])->assertCreated();

        // The raw model response serializes the `date` cast as a full
        // ISO datetime (Carbon's default JSON serialization) — the
        // entries() listing endpoint is what hand-formats this to a
        // plain date for display, asserted separately below.
        $this->assertStringStartsWith('2026-09-15', $response->json('entry.transaction_date'));
        $response->assertJsonPath('entry.paid_from', 'luqman');
        $response->assertJsonPath('entry.reference_no', 'BANK-REF-42');

        // entries() hand-formats transaction_date as a plain date, unlike storeEntry()'s raw-model response above.
        $listed = collect($this->getJson("/api/accounting/envelopes/{$envelope->id}/entries")->json('entries'))->firstWhere('reference_no', 'BANK-REF-42');
        $this->assertSame('2026-09-15', $listed['transaction_date']);
        $this->assertSame('Luqman (personal)', $listed['paid_from_label']);
    }

    /** Omitting transaction_date defaults it to today, never leaves it null — an entry always has a real date. */
    public function test_recording_an_entry_without_a_transaction_date_defaults_to_today(): void
    {
        $this->actAsSuperAdmin();
        $envelope = BudgetEnvelope::query()->where('name', 'Capital Rolling')->firstOrFail();

        $response = $this->postJson("/api/accounting/envelopes/{$envelope->id}/entries", [
            'category' => BudgetEnvelopeEntryCategory::CapitalInjection->value,
            'amount_sen' => 100000,
            'description' => 'no date given',
        ])->assertCreated();

        $this->assertStringStartsWith(now('Asia/Kuala_Lumpur')->toDateString(), $response->json('entry.transaction_date'));
    }

    /** Item 63: "today" is the KL day — at 07:30 KL it is still yesterday in UTC. */
    public function test_the_default_transaction_date_is_the_kl_day_not_the_utc_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 23:30:00', 'UTC')); // 2026-10-04 07:30 KL
        $this->actAsSuperAdmin();
        $envelope = BudgetEnvelope::query()->where('name', 'Capital Rolling')->firstOrFail();

        $response = $this->postJson("/api/accounting/envelopes/{$envelope->id}/entries", [
            'category' => BudgetEnvelopeEntryCategory::CapitalInjection->value, 'amount_sen' => 100000, 'description' => 'early morning',
        ])->assertCreated();

        $this->assertStringStartsWith('2026-10-04', $response->json('entry.transaction_date'));
    }

    /**
     * Item 63: the date filter is the money date (transaction_date), and a
     * void's reversal carries the date of the entry it cancels — so the
     * filtered period still nets the pair to zero.
     */
    public function test_the_date_filter_uses_transaction_date_and_keeps_a_void_with_its_entry(): void
    {
        $this->actAsSuperAdmin();
        $envelope = BudgetEnvelope::query()->where('name', 'Capital Rolling')->firstOrFail();
        $created = $this->postJson("/api/accounting/envelopes/{$envelope->id}/entries", [
            'category' => BudgetEnvelopeEntryCategory::CapitalInjection->value, 'amount_sen' => 100000,
            'description' => 'back-dated', 'transaction_date' => '2026-09-15',
        ])->assertCreated();
        $this->postJson('/api/accounting/envelope-entries/'.$created->json('entry.id').'/void', ['reason' => 'wrong'])->assertCreated();

        $september = $this->getJson("/api/accounting/envelopes/{$envelope->id}/entries?from=2026-09-15&to=2026-09-15")->assertOk()->json('entries');
        $this->assertCount(2, $september, 'the entry and its reversal, both dated 2026-09-15');
        $this->assertSame(0, collect($september)->sum('amount_sen'));

        $today = now('Asia/Kuala_Lumpur')->toDateString();
        $this->assertCount(0, $this->getJson("/api/accounting/envelopes/{$envelope->id}/entries?from={$today}&to={$today}")->json('entries'));
    }

    /**
     * 2026-09-30 addendum — Bucket C decision 6: only ONE new category
     * (Director Repayment), deliberately no matching "Director Advance"
     * — the advance itself never moves envelope cash (the director
     * spent their own money, not the company's), so it doesn't fit
     * this table's "amount_sen is real money moving through THIS
     * envelope" model.
     */
    public function test_director_repayment_category_is_available_and_debits_the_envelope(): void
    {
        $this->actAsSuperAdmin();
        $envelope = BudgetEnvelope::query()->where('name', 'Capital Rolling')->firstOrFail();
        $this->postJson("/api/accounting/envelopes/{$envelope->id}/entries", [
            'category' => BudgetEnvelopeEntryCategory::CapitalInjection->value, 'amount_sen' => 500000, 'description' => 'seed',
        ]);

        $categories = collect($this->getJson('/api/accounting/envelopes')->json('categories'))->pluck('value');
        $this->assertContains('director_repayment', $categories);
        $this->assertNotContains('director_advance', $categories, 'an advance never moves envelope cash, so it has no category here');

        $response = $this->postJson("/api/accounting/envelopes/{$envelope->id}/entries", [
            'category' => BudgetEnvelopeEntryCategory::DirectorRepayment->value,
            'amount_sen' => 300000,
            'description' => 'Repaying Luqman for pre-capital spending',
            'paid_from' => 'company_account',
        ])->assertCreated();

        $this->assertSame(-300000, $response->json('entry.amount_sen'));
        $this->assertSame(200000, $envelope->fresh()->balanceSen());
    }

    /** OpexAdvertising's typical sign is negative — a plain positive magnitude in the request still debits the envelope. */
    public function test_recording_an_opex_entry_debits_the_envelope(): void
    {
        $this->actAsSuperAdmin();
        $envelope = BudgetEnvelope::query()->where('name', 'Marketing Budget')->firstOrFail();
        $this->postJson("/api/accounting/envelopes/{$envelope->id}/entries", [
            'category' => BudgetEnvelopeEntryCategory::CapitalInjection->value, 'amount_sen' => 1000000, 'description' => 'seed',
        ]);

        $response = $this->postJson("/api/accounting/envelopes/{$envelope->id}/entries", [
            'category' => BudgetEnvelopeEntryCategory::OpexAdvertising->value,
            'amount_sen' => 25000,
            'description' => 'Facebook Ads September',
        ])->assertCreated();

        $this->assertSame(-25000, $response->json('entry.amount_sen'));
        $this->assertSame(975000, $envelope->fresh()->balanceSen());
    }

    public function test_recording_a_receipt_stores_it_privately_and_it_can_be_downloaded(): void
    {
        Storage::fake(config('filesystems.accounting_disk'));
        $this->actAsSuperAdmin();
        $envelope = BudgetEnvelope::query()->where('name', 'Marketing Budget')->firstOrFail();

        $response = $this->post("/api/accounting/envelopes/{$envelope->id}/entries", [
            'category' => BudgetEnvelopeEntryCategory::OpexSoftware->value,
            'amount_sen' => 5000,
            'description' => 'Canva subscription',
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $entryId = $response->json('entry.id');
        Storage::disk(config('filesystems.accounting_disk'))->assertExists($response->json('entry.receipt_path'));

        $this->get("/api/accounting/envelope-entries/{$entryId}/receipt")->assertOk();
    }

    /** Adjustment is the one category allowed either sign — requires an explicit `direction`. */
    public function test_adjustment_category_requires_a_direction(): void
    {
        $this->actAsSuperAdmin();
        $envelope = BudgetEnvelope::query()->where('name', 'Capital Rolling')->firstOrFail();

        $this->postJson("/api/accounting/envelopes/{$envelope->id}/entries", [
            'category' => BudgetEnvelopeEntryCategory::Adjustment->value,
            'amount_sen' => 500,
            'description' => 'typo correction',
        ])->assertUnprocessable();

        $response = $this->postJson("/api/accounting/envelopes/{$envelope->id}/entries", [
            'category' => BudgetEnvelopeEntryCategory::Adjustment->value,
            'amount_sen' => 500,
            'description' => 'typo correction',
            'direction' => 'out',
        ])->assertCreated();

        $this->assertSame(-500, $response->json('entry.amount_sen'));
    }

    /**
     * The whole point of the append-only design: a void inserts a new,
     * negated entry rather than editing the original — the envelope
     * balance nets to exactly what it should via a plain SUM, and the
     * original stays visible (never zeroed/deleted).
     */
    public function test_voiding_an_entry_reverses_the_balance_and_keeps_the_original_row(): void
    {
        $admin = $this->actAsSuperAdmin();
        $envelope = BudgetEnvelope::query()->where('name', 'Capital Rolling')->firstOrFail();
        $created = $this->postJson("/api/accounting/envelopes/{$envelope->id}/entries", [
            'category' => BudgetEnvelopeEntryCategory::CapitalInjection->value, 'amount_sen' => 100000, 'description' => 'oops wrong amount',
        ])->assertCreated();
        $entryId = $created->json('entry.id');

        $response = $this->postJson("/api/accounting/envelope-entries/{$entryId}/void", ['reason' => 'typed the wrong amount'])->assertCreated();

        $this->assertSame(0, $response->json('balance_sen'));
        $this->assertSame(-100000, $response->json('reversal.amount_sen'));
        $this->assertDatabaseHas('budget_envelope_entries', ['id' => $entryId, 'amount_sen' => 100000]);

        $entries = $this->getJson("/api/accounting/envelopes/{$envelope->id}/entries")->assertOk()->json('entries');
        $original = collect($entries)->firstWhere('id', $entryId);
        $this->assertTrue($original['is_voided']);
    }

    public function test_cannot_void_an_already_voided_entry(): void
    {
        $this->actAsSuperAdmin();
        $envelope = BudgetEnvelope::query()->where('name', 'Capital Rolling')->firstOrFail();
        $created = $this->postJson("/api/accounting/envelopes/{$envelope->id}/entries", [
            'category' => BudgetEnvelopeEntryCategory::CapitalInjection->value, 'amount_sen' => 100000, 'description' => 'x',
        ])->assertCreated();
        $entryId = $created->json('entry.id');
        $this->postJson("/api/accounting/envelope-entries/{$entryId}/void", ['reason' => 'first void'])->assertCreated();

        $this->postJson("/api/accounting/envelope-entries/{$entryId}/void", ['reason' => 'second void'])->assertUnprocessable();
    }

    public function test_model_layer_rejects_updating_or_deleting_a_persisted_entry(): void
    {
        $envelope = BudgetEnvelope::query()->where('name', 'Capital Rolling')->firstOrFail();
        $entry = BudgetEnvelopeEntry::query()->create([
            'budget_envelope_id' => $envelope->id, 'category' => BudgetEnvelopeEntryCategory::CapitalInjection->value,
            'amount_sen' => 1000, 'description' => 'x',
        ]);

        $this->expectException(\LogicException::class);
        $entry->update(['amount_sen' => 2000]);
    }

    public function test_allocate_monthly_profit_writes_one_entry_per_chosen_envelope(): void
    {
        $this->actAsSuperAdmin();
        $capital = BudgetEnvelope::query()->where('name', 'Capital Rolling')->firstOrFail();
        $marketing = BudgetEnvelope::query()->where('name', 'Marketing Budget')->firstOrFail();

        $response = $this->postJson('/api/accounting/envelopes/allocate-monthly-profit', [
            'period_label' => 'September 2026',
            'allocations' => [
                ['budget_envelope_id' => $capital->id, 'amount_sen' => 500000],
                ['budget_envelope_id' => $marketing->id, 'amount_sen' => 200000],
            ],
        ])->assertCreated();

        $this->assertCount(2, $response->json('entries'));
        $this->assertSame(500000, $capital->fresh()->balanceSen());
        $this->assertSame(200000, $marketing->fresh()->balanceSen());
        $this->assertSame('monthly_profit_allocation', $response->json('entries.0.category'));
    }

    public function test_export_streams_a_csv(): void
    {
        $this->actAsSuperAdmin();
        $envelope = BudgetEnvelope::query()->where('name', 'Capital Rolling')->firstOrFail();
        $this->postJson("/api/accounting/envelopes/{$envelope->id}/entries", [
            'category' => BudgetEnvelopeEntryCategory::CapitalInjection->value, 'amount_sen' => 100000, 'description' => 'CSV-TEST-DESC',
        ])->assertCreated();

        $response = $this->get('/api/accounting/envelopes/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('CSV-TEST-DESC', $content);
        $this->assertStringContainsString('"Recorded At","Transaction Date",Envelope,Category', $content);
    }

    /**
     * Regression guard for exactly the bug class this addendum's own
     * grilling session flagged as a past incident (Supplier Funding's
     * register once silently dropped void visibility): the export must
     * show BOTH the original entry (tagged Voided, untouched figures)
     * AND its reversal (tagged Void reversal) — never zero rows, never
     * a silently-vanished original.
     */
    public function test_export_shows_both_a_voided_entry_and_its_reversal(): void
    {
        $this->actAsSuperAdmin();
        $envelope = BudgetEnvelope::query()->where('name', 'Capital Rolling')->firstOrFail();
        $created = $this->postJson("/api/accounting/envelopes/{$envelope->id}/entries", [
            'category' => BudgetEnvelopeEntryCategory::CapitalInjection->value, 'amount_sen' => 100000, 'description' => 'EXPORT-VOID-TEST',
        ])->assertCreated();
        $entryId = $created->json('entry.id');
        $this->postJson("/api/accounting/envelope-entries/{$entryId}/void", ['reason' => 'export test'])->assertCreated();

        $rows = array_map(
            fn (string $line) => str_getcsv($line),
            array_filter(explode("\n", $this->get('/api/accounting/envelopes/export')->streamedContent())),
        );

        $original = collect($rows)->first(fn ($r) => ($r[5] ?? null) === 'EXPORT-VOID-TEST');
        $reversal = collect($rows)->first(fn ($r) => str_contains($r[5] ?? '', "reversing entry #{$entryId}"));

        $this->assertNotNull($original, 'the original entry must still appear in the export, never dropped');
        $this->assertSame('1000.00', $original[4]);
        $this->assertSame('Voided', $original[10]);
        $this->assertNotNull($reversal);
        $this->assertSame('-1000.00', $reversal[4]);
        $this->assertSame('Void reversal', $reversal[10]);
    }
}
