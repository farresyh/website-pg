<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Supplier;
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
}
