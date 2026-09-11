<?php

namespace Tests\Feature\Models;

use App\Models\Supplier;
use App\Models\SupplierTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierTransferTest extends TestCase
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

    public function test_myr_amounts_stay_integer_sen_and_foreign_amount_keeps_decimal_precision(): void
    {
        $supplier = $this->makeSupplier();

        $transfer = SupplierTransfer::query()->create([
            'supplier_id' => $supplier->id,
            'source_channel' => 'wise',
            'amount_myr_sent' => 100000, // RM 1,000.00
            'fee_myr' => 250, // RM 2.50
            'currency' => 'IDR',
            'amount_foreign_received' => '3700000.0000',
            'effective_rate' => '0.00027027',
            'reference_no' => 'WISE-REF-1',
        ]);

        $reloaded = SupplierTransfer::query()->findOrFail($transfer->id);

        $this->assertSame(100000, $reloaded->amount_myr_sent);
        $this->assertSame(250, $reloaded->fee_myr);
        $this->assertSame('3700000.0000', $reloaded->amount_foreign_received);
        $this->assertSame('0.00027027', $reloaded->effective_rate);
    }

    public function test_receipt_path_is_optional(): void
    {
        $supplier = $this->makeSupplier();

        $transfer = SupplierTransfer::query()->create([
            'supplier_id' => $supplier->id,
            'source_channel' => 'bank',
            'amount_myr_sent' => 50000,
            'currency' => 'IDR',
            'amount_foreign_received' => '1850000.0000',
        ]);

        $this->assertNull($transfer->fresh()->receipt_path);
    }

    public function test_belongs_to_supplier(): void
    {
        $supplier = $this->makeSupplier();

        $transfer = SupplierTransfer::query()->create([
            'supplier_id' => $supplier->id,
            'source_channel' => 'wise',
            'amount_myr_sent' => 100000,
            'currency' => 'IDR',
            'amount_foreign_received' => '3700000.0000',
        ]);

        $this->assertTrue($transfer->supplier->is($supplier));
        $this->assertTrue($supplier->transfers()->first()->is($transfer));
    }
}
