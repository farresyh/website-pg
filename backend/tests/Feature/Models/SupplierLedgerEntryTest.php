<?php

namespace Tests\Feature\Models;

use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Services\Accounting\SupplierLedgerEntryType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierLedgerEntryTest extends TestCase
{
    use RefreshDatabase;

    private function makeSupplier(): Supplier
    {
        return Supplier::query()->create([
            'name' => 'Digiflazz',
            'slug' => 'digiflazz',
            'api_config' => [],
            'currency' => 'IDR',
        ]);
    }

    public function test_amount_is_signed_and_kept_in_the_supplier_currency(): void
    {
        $supplier = $this->makeSupplier();

        $entry = SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id,
            'type' => SupplierLedgerEntryType::Topup->value,
            'amount' => '5000000.0000',
            'currency' => 'IDR',
        ]);

        $reloaded = SupplierLedgerEntry::query()->findOrFail($entry->id);

        $this->assertSame('5000000.0000', $reloaded->amount);
        $this->assertSame('IDR', $reloaded->currency);
    }

    /**
     * ADR-083 decision 2: append-only is enforced at the model layer, not
     * just by convention — an update on a persisted row must throw, not
     * silently succeed.
     */
    public function test_updating_a_persisted_row_throws(): void
    {
        $supplier = $this->makeSupplier();

        $entry = SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id,
            'type' => SupplierLedgerEntryType::OrderDrawdown->value,
            'amount' => '-15000.0000',
            'currency' => 'IDR',
        ]);

        $this->expectException(\LogicException::class);

        $entry->amount = '-16000.0000';
        $entry->save();
    }

    public function test_deleting_a_persisted_row_throws(): void
    {
        $supplier = $this->makeSupplier();

        $entry = SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id,
            'type' => SupplierLedgerEntryType::Refund->value,
            'amount' => '15000.0000',
            'currency' => 'IDR',
        ]);

        $this->expectException(\LogicException::class);

        $entry->delete();
    }

    public function test_supplier_ledger_balance_sums_signed_entries_in_supplier_currency(): void
    {
        $supplier = $this->makeSupplier();

        SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id,
            'type' => SupplierLedgerEntryType::Topup->value,
            'amount' => '5000000.0000',
            'currency' => 'IDR',
        ]);

        SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id,
            'type' => SupplierLedgerEntryType::OrderDrawdown->value,
            'amount' => '-15000.0000',
            'currency' => 'IDR',
        ]);

        $this->assertSame('4985000.0000', $supplier->supplierLedgerBalance());
    }
}
