<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-083 decision 2 (PR-1): "Record Supplier Transfer" — under /accounting,
 * not /middleware, per this controller's own docblock.
 */
class SupplierTransferControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actAsSuperAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->superAdmin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function makeSupplier(): Supplier
    {
        return Supplier::query()->create([
            'name' => 'Digiflazz',
            'slug' => 'digiflazz',
            'api_config' => [],
            'currency' => 'IDR',
        ]);
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        $supplier = $this->makeSupplier();

        $this->getJson("/api/accounting/suppliers/{$supplier->id}/transfers")->assertForbidden();
    }

    public function test_store_records_the_transfer_and_the_topup_ledger_entry(): void
    {
        $admin = $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();

        $response = $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'wise',
            'amount_myr_sent' => 100000, // RM 1,000.00
            'fee_myr' => 250,
            'currency' => 'IDR',
            'amount_foreign_received' => 3700000,
            'reference_no' => 'WISE-REF-1',
        ]);

        $response->assertCreated()
            ->assertJsonPath('transfer.supplier_id', $supplier->id)
            ->assertJsonPath('transfer.amount_myr_sent', 100000)
            ->assertJsonPath('ledger_balance', '3700000.0000');

        $this->assertDatabaseHas('supplier_transfers', [
            'supplier_id' => $supplier->id,
            'source_channel' => 'wise',
            'amount_myr_sent' => 100000,
            'fee_myr' => 250,
            'reference_no' => 'WISE-REF-1',
        ]);

        $this->assertDatabaseHas('supplier_ledger_entries', [
            'supplier_id' => $supplier->id,
            'type' => 'TOPUP',
            'amount' => '3700000.0000',
            'currency' => 'IDR',
            'reference_type' => 'supplier_transfer',
            'created_by' => $admin->id,
        ]);
    }

    public function test_effective_rate_is_derived_from_the_two_actual_amounts(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();

        $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'wise',
            'amount_myr_sent' => 100000, // RM 1,000.00
            'currency' => 'IDR',
            'amount_foreign_received' => 3700000,
        ])->assertCreated();

        $transfer = SupplierTransfer::query()->firstOrFail();
        $this->assertSame('0.00027027', $transfer->effective_rate);
    }

    public function test_store_with_receipt_stores_the_file_privately(): void
    {
        Storage::fake('local');
        $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();

        $response = $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'bank',
            'amount_myr_sent' => 50000,
            'currency' => 'IDR',
            'amount_foreign_received' => 1850000,
            'receipt' => UploadedFile::fake()->create('transfer-receipt.pdf', 200, 'application/pdf'),
        ]);

        $response->assertCreated();
        $transfer = SupplierTransfer::query()->firstOrFail();
        $this->assertNotNull($transfer->receipt_path);
        Storage::disk('local')->assertExists($transfer->receipt_path);
    }

    public function test_store_rejects_zero_amount(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();

        $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'wise',
            'amount_myr_sent' => 0,
            'currency' => 'IDR',
            'amount_foreign_received' => 3700000,
        ])->assertUnprocessable()->assertJsonValidationErrors('amount_myr_sent');
    }

    public function test_store_rejects_an_unknown_source_channel(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();

        $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'paypal',
            'amount_myr_sent' => 50000,
            'currency' => 'IDR',
            'amount_foreign_received' => 1850000,
        ])->assertUnprocessable()->assertJsonValidationErrors('source_channel');
    }

    public function test_index_returns_ledger_balance_and_transfer_history(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();
        $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'wise',
            'amount_myr_sent' => 50000,
            'currency' => 'IDR',
            'amount_foreign_received' => 1850000,
        ])->assertCreated();

        $response = $this->getJson("/api/accounting/suppliers/{$supplier->id}/transfers");

        $response->assertOk()
            ->assertJsonPath('ledger_balance', '1850000.0000')
            ->assertJsonPath('currency', 'IDR');
        $this->assertCount(1, $response->json('transfers.data'));
    }

    public function test_download_receipt_streams_the_file(): void
    {
        Storage::fake('local');
        $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();
        $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'wise',
            'amount_myr_sent' => 50000,
            'currency' => 'IDR',
            'amount_foreign_received' => 1850000,
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 50, 'application/pdf'),
        ])->assertCreated();
        $transfer = SupplierTransfer::query()->firstOrFail();

        $response = $this->get("/api/accounting/supplier-transfers/{$transfer->id}/receipt");

        $response->assertOk();
    }

    public function test_download_receipt_404s_when_none_was_attached(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();
        $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'wise',
            'amount_myr_sent' => 50000,
            'currency' => 'IDR',
            'amount_foreign_received' => 1850000,
        ])->assertCreated();
        $transfer = SupplierTransfer::query()->firstOrFail();

        $this->get("/api/accounting/supplier-transfers/{$transfer->id}/receipt")->assertNotFound();
    }

    // ── ADR-083 2026-09-15 addendum: supplier_fee ────────────────────────

    /**
     * The founder's own real Wise receipt: 198.89 MYR sent, 832,672
     * IDR gross to Digiflazz, Digiflazz's own 15,000 IDR deposit fee.
     * The ledger must credit the *net* wallet credit, never the gross.
     */
    public function test_store_with_a_supplier_fee_credits_the_ledger_net_not_gross(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();

        $response = $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'wise',
            'amount_myr_sent' => 19889, // RM 198.89
            'fee_myr' => 670, // RM 6.70, Wise's own fee
            'currency' => 'IDR',
            'amount_foreign_received' => 832672,
            'supplier_fee' => 15000, // Digiflazz's own deposit fee
        ]);

        $response->assertCreated();
        $response->assertJsonPath('transfer.amount_foreign_received', '832672.0000');
        $response->assertJsonPath('transfer.supplier_fee', '15000.0000');
        // 817,672 net — this is the actual point of the fix.
        $response->assertJsonPath('ledger_balance', '817672.0000');

        $this->assertDatabaseHas('supplier_transfers', [
            'supplier_id' => $supplier->id,
            'amount_foreign_received' => '832672.0000',
            'supplier_fee' => '15000.0000',
        ]);
        $this->assertDatabaseHas('supplier_ledger_entries', [
            'supplier_id' => $supplier->id,
            'type' => 'TOPUP',
            'amount' => '817672.0000',
        ]);
    }

    /** No supplier_fee sent — net equals gross, same as before this addendum (no regression for a channel with no such fee). */
    public function test_store_without_a_supplier_fee_credits_the_full_gross_amount(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();

        $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'wise',
            'amount_myr_sent' => 100000,
            'currency' => 'IDR',
            'amount_foreign_received' => 3700000,
        ])->assertCreated()->assertJsonPath('ledger_balance', '3700000.0000');
    }

    /** effective_rate is now the *true* cost per net (usable) unit — a gross-based rate would understate the real cost. */
    public function test_effective_rate_is_derived_from_the_net_amount_when_a_supplier_fee_is_set(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();

        $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'wise',
            'amount_myr_sent' => 19889,
            'currency' => 'IDR',
            'amount_foreign_received' => 832672,
            'supplier_fee' => 15000,
        ])->assertCreated();

        $transfer = SupplierTransfer::query()->firstOrFail();
        // 198.89 / 817,672 — net, not 198.89 / 832,672 (gross).
        $this->assertSame(number_format(198.89 / 817672, 8, '.', ''), $transfer->effective_rate);
    }

    public function test_store_rejects_a_supplier_fee_larger_than_the_gross_amount(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();

        $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'wise',
            'amount_myr_sent' => 19889,
            'currency' => 'IDR',
            'amount_foreign_received' => 10000,
            'supplier_fee' => 15000,
        ])->assertUnprocessable()->assertJsonValidationErrors('supplier_fee');
    }

    // ── ADR-083 2026-09-15 addendum: corrections (Adjust / Void) ─────────

    public function test_adjust_records_a_signed_manual_adjustment_without_touching_the_original_topup(): void
    {
        $admin = $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();
        $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'wise',
            'amount_myr_sent' => 19889,
            'currency' => 'IDR',
            'amount_foreign_received' => 832672,
        ])->assertCreated();
        $transfer = SupplierTransfer::query()->firstOrFail();

        // Correcting a transfer recorded *before* supplier_fee existed
        // — the exact real scenario this addendum was built for.
        $response = $this->postJson("/api/accounting/supplier-transfers/{$transfer->id}/adjust", [
            'amount' => '-15000',
            'reason' => 'Digiflazz deposit fee missed at entry time',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('entry.amount', '-15000.0000');
        $response->assertJsonPath('entry.reason', 'Digiflazz deposit fee missed at entry time');
        $response->assertJsonPath('ledger_balance', '817672.0000');

        // Original TOPUP row is untouched — still the gross 832,672.
        $this->assertDatabaseHas('supplier_ledger_entries', [
            'reference_type' => 'supplier_transfer', 'reference_id' => $transfer->id,
            'type' => 'TOPUP', 'amount' => '832672.0000',
        ]);
        $this->assertDatabaseHas('supplier_ledger_entries', [
            'reference_type' => 'supplier_transfer', 'reference_id' => $transfer->id,
            'type' => 'MANUAL_ADJUSTMENT', 'amount' => '-15000.0000', 'created_by' => $admin->id,
        ]);
        // The transfer itself is untouched — only a real "money never arrived" case gets voided.
        $this->assertNull($transfer->fresh()->voided_at);
    }

    public function test_adjust_requires_a_reason(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();
        $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'wise', 'amount_myr_sent' => 19889, 'currency' => 'IDR', 'amount_foreign_received' => 832672,
        ])->assertCreated();
        $transfer = SupplierTransfer::query()->firstOrFail();

        $this->postJson("/api/accounting/supplier-transfers/{$transfer->id}/adjust", ['amount' => '-15000'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_adjust_rejects_a_zero_amount(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();
        $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'wise', 'amount_myr_sent' => 19889, 'currency' => 'IDR', 'amount_foreign_received' => 832672,
        ])->assertCreated();
        $transfer = SupplierTransfer::query()->firstOrFail();

        $this->postJson("/api/accounting/supplier-transfers/{$transfer->id}/adjust", ['amount' => '0', 'reason' => 'no-op'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    /** The other real scenario: the money never reached the supplier at all. */
    public function test_void_fully_reverses_the_transfer_and_marks_it_voided(): void
    {
        $admin = $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();
        $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'wise', 'amount_myr_sent' => 19889, 'currency' => 'IDR',
            'amount_foreign_received' => 832672, 'supplier_fee' => 15000,
        ])->assertCreated();
        $transfer = SupplierTransfer::query()->firstOrFail();

        $response = $this->postJson("/api/accounting/supplier-transfers/{$transfer->id}/void", [
            'reason' => 'Wrong account number — funds never arrived, confirmed with Wise support',
        ]);

        $response->assertCreated();
        // Reverses the net (817,672) that was actually credited, not the gross.
        $response->assertJsonPath('entry.amount', '-817672.0000');
        $response->assertJsonPath('ledger_balance', '0.0000');
        $response->assertJsonPath('transfer.voided_at', fn ($v) => $v !== null);

        $this->assertDatabaseHas('supplier_transfers', ['id' => $transfer->id, 'void_reason' => 'Wrong account number — funds never arrived, confirmed with Wise support']);
        $this->assertNotNull($transfer->fresh()->voided_at);
        $this->assertDatabaseHas('supplier_ledger_entries', [
            'reference_type' => 'supplier_transfer', 'reference_id' => $transfer->id,
            'type' => 'MANUAL_ADJUSTMENT', 'amount' => '-817672.0000', 'created_by' => $admin->id,
        ]);
    }

    public function test_void_requires_a_reason(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();
        $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'wise', 'amount_myr_sent' => 19889, 'currency' => 'IDR', 'amount_foreign_received' => 832672,
        ])->assertCreated();
        $transfer = SupplierTransfer::query()->firstOrFail();

        $this->postJson("/api/accounting/supplier-transfers/{$transfer->id}/void", [])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_cannot_adjust_or_void_an_already_voided_transfer(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();
        $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'wise', 'amount_myr_sent' => 19889, 'currency' => 'IDR', 'amount_foreign_received' => 832672,
        ])->assertCreated();
        $transfer = SupplierTransfer::query()->firstOrFail();
        $this->postJson("/api/accounting/supplier-transfers/{$transfer->id}/void", ['reason' => 'first void'])->assertCreated();

        $this->postJson("/api/accounting/supplier-transfers/{$transfer->id}/void", ['reason' => 'second void attempt'])
            ->assertUnprocessable();
        $this->postJson("/api/accounting/supplier-transfers/{$transfer->id}/adjust", ['amount' => '-1', 'reason' => 'adjust after void'])
            ->assertUnprocessable();

        // Only the one real reversal entry exists — the rejected calls wrote nothing.
        $this->assertSame(1, SupplierLedgerEntry::query()->where('type', 'MANUAL_ADJUSTMENT')->count());
    }

    public function test_regular_admin_cannot_adjust_or_void(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();
        $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'wise', 'amount_myr_sent' => 19889, 'currency' => 'IDR', 'amount_foreign_received' => 832672,
        ])->assertCreated();
        $transfer = SupplierTransfer::query()->firstOrFail();

        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->postJson("/api/accounting/supplier-transfers/{$transfer->id}/adjust", ['amount' => '-1', 'reason' => 'x'])->assertForbidden();
        $this->postJson("/api/accounting/supplier-transfers/{$transfer->id}/void", ['reason' => 'x'])->assertForbidden();
    }

    /** index()'s transfer history nests each transfer's own corrections, so an admin sees the full story in one place. */
    public function test_index_includes_each_transfers_adjustments(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->makeSupplier();
        $this->postJson("/api/accounting/suppliers/{$supplier->id}/transfers", [
            'source_channel' => 'wise', 'amount_myr_sent' => 19889, 'currency' => 'IDR', 'amount_foreign_received' => 832672,
        ])->assertCreated();
        $transfer = SupplierTransfer::query()->firstOrFail();
        $this->postJson("/api/accounting/supplier-transfers/{$transfer->id}/adjust", ['amount' => '-15000', 'reason' => 'missed fee'])->assertCreated();

        $response = $this->getJson("/api/accounting/suppliers/{$supplier->id}/transfers");

        $response->assertOk();
        $response->assertJsonCount(1, 'transfers.data.0.adjustments');
        $response->assertJsonPath('transfers.data.0.adjustments.0.amount', '-15000.0000');
    }
}
