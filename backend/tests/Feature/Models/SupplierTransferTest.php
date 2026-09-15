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

    // ── ADR-083 2026-09-15 addendum ───────────────────────────────────────

    public function test_net_foreign_received_subtracts_the_supplier_fee(): void
    {
        $supplier = $this->makeSupplier();

        $transfer = SupplierTransfer::query()->create([
            'supplier_id' => $supplier->id,
            'source_channel' => 'wise',
            'amount_myr_sent' => 19889,
            'currency' => 'IDR',
            'amount_foreign_received' => '832672.0000',
            'supplier_fee' => '15000.0000',
        ]);

        $this->assertSame('817672.0000', $transfer->netForeignReceived());
    }

    /** No supplier_fee set — net equals the gross figure, same as before this addendum. */
    public function test_net_foreign_received_equals_gross_when_no_supplier_fee_is_set(): void
    {
        $supplier = $this->makeSupplier();

        $transfer = SupplierTransfer::query()->create([
            'supplier_id' => $supplier->id,
            'source_channel' => 'wise',
            'amount_myr_sent' => 100000,
            'currency' => 'IDR',
            'amount_foreign_received' => '3700000.0000',
        ]);

        $this->assertSame('3700000.0000', $transfer->netForeignReceived());
    }

    /** decision 7: a void/adjust correction is only ever a new ledger row — the transfer itself may still gain void metadata (it was always documented as "not append-only itself"). */
    public function test_voided_at_and_void_reason_are_settable(): void
    {
        $supplier = $this->makeSupplier();
        $transfer = SupplierTransfer::query()->create([
            'supplier_id' => $supplier->id,
            'source_channel' => 'wise',
            'amount_myr_sent' => 19889,
            'currency' => 'IDR',
            'amount_foreign_received' => '832672.0000',
        ]);

        $transfer->update(['voided_at' => now(), 'void_reason' => 'Wrong account number']);

        $reloaded = $transfer->fresh();
        $this->assertNotNull($reloaded->voided_at);
        $this->assertSame('Wrong account number', $reloaded->void_reason);
    }
}
