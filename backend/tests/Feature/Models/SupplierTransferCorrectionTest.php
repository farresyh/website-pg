<?php

namespace Tests\Feature\Models;

use App\Models\Supplier;
use App\Models\SupplierTransfer;
use App\Models\SupplierTransferCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ADR-083 2026-09-28 addendum: the metadata-only correction audit trail — append-only, model-enforced, mirrors SupplierLedgerEntry. */
class SupplierTransferCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function makeTransfer(): SupplierTransfer
    {
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);

        return SupplierTransfer::query()->create([
            'transferred_on' => '2026-09-05',
            'supplier_id' => $supplier->id,
            'source_channel' => 'wise',
            'amount_myr_sent' => 100000,
            'currency' => 'IDR',
            'amount_foreign_received' => '3700000.0000',
        ]);
    }

    public function test_changes_are_cast_to_an_array(): void
    {
        $transfer = $this->makeTransfer();

        $correction = SupplierTransferCorrection::query()->create([
            'supplier_transfer_id' => $transfer->id,
            'changes' => ['reference_no' => ['WISE-OLD', 'WISE-NEW']],
            'reason' => 'typo',
        ]);

        $reloaded = SupplierTransferCorrection::query()->findOrFail($correction->id);
        $this->assertSame(['reference_no' => ['WISE-OLD', 'WISE-NEW']], $reloaded->changes);
    }

    public function test_updating_a_persisted_row_throws(): void
    {
        $transfer = $this->makeTransfer();
        $correction = SupplierTransferCorrection::query()->create([
            'supplier_transfer_id' => $transfer->id,
            'changes' => ['reference_no' => ['WISE-OLD', 'WISE-NEW']],
            'reason' => 'typo',
        ]);

        $this->expectException(\LogicException::class);

        $correction->reason = 'changed my mind';
        $correction->save();
    }

    public function test_deleting_a_persisted_row_throws(): void
    {
        $transfer = $this->makeTransfer();
        $correction = SupplierTransferCorrection::query()->create([
            'supplier_transfer_id' => $transfer->id,
            'changes' => ['reference_no' => ['WISE-OLD', 'WISE-NEW']],
            'reason' => 'typo',
        ]);

        $this->expectException(\LogicException::class);

        $correction->delete();
    }

    public function test_belongs_to_the_transfer_it_corrects(): void
    {
        $transfer = $this->makeTransfer();
        $correction = SupplierTransferCorrection::query()->create([
            'supplier_transfer_id' => $transfer->id,
            'changes' => ['reference_no' => ['WISE-OLD', 'WISE-NEW']],
            'reason' => 'typo',
        ]);

        $this->assertTrue($correction->supplierTransfer->is($transfer));
        $this->assertTrue($transfer->corrections()->first()->is($correction));
    }
}
