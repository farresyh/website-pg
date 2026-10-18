<?php

namespace Tests\Feature\Services\Accounting;

use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use App\Services\Accounting\CashPositionService;
use App\Services\Accounting\FundedCostOfSales;
use App\Services\Accounting\SupplierFundingService;
use App\Services\Accounting\SupplierLedgerEntryType;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminOrderControllerFixtures;
use Tests\TestCase;

/**
 * ADR-125 decisions 19-20 and the 2026-10-18 addendum (decisions 29-30):
 * an MYR supplier's unit is worth RM1 everywhere, a pay-after supplier's
 * negative balance is a payable rather than netting against another
 * supplier's prepaid asset, and a sandbox order never touches a real
 * supplier ledger.
 */
class Adr125ManualSupplierAccountingTest extends TestCase
{
    use AdminOrderControllerFixtures;
    use RefreshDatabase;

    private function manual(string $fundingMode): Supplier
    {
        return Supplier::query()->create([
            'name' => "Manual {$fundingMode}", 'slug' => 'manual-'.uniqid(), 'driver' => Supplier::DRIVER_MANUAL,
            'currency' => 'MYR', 'funding_mode' => $fundingMode, 'api_config' => [],
        ]);
    }

    private function ledger(Supplier $supplier, SupplierLedgerEntryType $type, string $amount, int $referenceId): void
    {
        SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id, 'type' => $type->value, 'amount' => $amount,
            'currency' => $supplier->currency, 'reference_type' => 'order', 'reference_id' => $referenceId,
        ]);
    }

    public function test_an_myr_suppliers_rate_is_one_with_or_without_transfers(): void
    {
        $supplier = $this->manual(Supplier::FUNDING_PREPAID);
        $rates = app(FundedCostOfSales::class);

        $this->assertSame(1.0, $rates->weightedAverageRate($supplier->id, '2026-10-31'));

        // A transfer with a supplier fee would otherwise give 100 / 98 ≠ 1.
        SupplierTransfer::query()->create([
            'transferred_on' => '2026-10-05', 'supplier_id' => $supplier->id, 'source_channel' => 'bank',
            'amount_myr_sent' => 10000, 'currency' => 'MYR', 'amount_foreign_received' => '100.0000',
            'supplier_fee' => '2.0000', 'effective_rate' => '1.00000000',
        ]);

        $this->assertSame(1.0, $rates->weightedAverageRate($supplier->id, '2026-10-31'));
    }

    public function test_cash_position_splits_prepaid_assets_from_pay_after_payables(): void
    {
        $prepaid = $this->manual(Supplier::FUNDING_PREPAID);
        $payAfter = $this->manual(Supplier::FUNDING_PAY_AFTER);
        $this->ledger($prepaid, SupplierLedgerEntryType::ManualAdjustment, '200.0000', 1);
        $this->ledger($payAfter, SupplierLedgerEntryType::OrderDrawdown, '-65.0000', 2);

        $position = app(CashPositionService::class)->asOf((int) now('Asia/Kuala_Lumpur')->format('Y'), (int) now('Asia/Kuala_Lumpur')->format('n'));

        $this->assertSame(20000, $position['assets']['supplier_prepaid_sen']);
        $this->assertSame(6500, $position['claims']['supplier_payable_sen']);
        // The gap identity is unchanged: +200 − 65 either way.
        $this->assertSame(13500, $position['assets']['supplier_prepaid_sen'] - $position['claims']['supplier_payable_sen']);
    }

    public function test_a_sandbox_order_never_writes_a_supplier_drawdown(): void
    {
        $supplier = $this->manual(Supplier::FUNDING_PREPAID);
        $sandbox = $this->order(['is_test' => true, 'supplier_id' => $supplier->id, 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value]);
        $real = $this->order(['supplier_id' => $supplier->id, 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value]);

        app(SupplierFundingService::class)->recordOrderDrawdown($sandbox, 9.0);
        app(SupplierFundingService::class)->recordOrderDrawdown($real, 9.0);

        $this->assertDatabaseMissing('supplier_ledger_entries', ['reference_type' => 'order', 'reference_id' => $sandbox->id]);
        $this->assertDatabaseHas('supplier_ledger_entries', ['reference_type' => 'order', 'reference_id' => $real->id, 'type' => 'ORDER_DRAWDOWN']);
    }
}
