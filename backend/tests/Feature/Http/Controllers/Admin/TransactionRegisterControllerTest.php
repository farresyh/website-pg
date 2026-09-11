<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use App\Models\Voucher;
use App\Services\Accounting\SupplierLedgerEntryType;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-083 decision 9: the Transaction Register — one row per
 * money-moving event across four otherwise-separate sources.
 */
class TransactionRegisterControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actAsSuperAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->superAdmin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function supplier(): Supplier
    {
        return Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/accounting/transactions')->assertForbidden();
    }

    public function test_index_includes_a_paid_order_row(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'standard_selling_price' => 1000,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        $order = Order::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-REG-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '1',
            'game_id' => $game->id,
            'package_id' => $package->id,
            'supplier_id' => $supplier->id,
            'supplier_product_ref' => 'A',
            'cost_price' => 900,
            'standard_selling_price' => 1000,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
            'paid_at' => now(),
        ]);
        // Mirrors OrderFulfillmentService::creditProfit() — the real
        // source of truth for a delivered order's realized profit.
        LedgerEntry::query()->create([
            'owner_type' => LedgerOwnerType::Platform->value, 'owner_id' => null,
            'type' => 'order_profit', 'amount' => 100, 'reference_type' => 'order', 'reference_id' => $order->id,
        ]);

        $response = $this->getJson('/api/accounting/transactions')->assertOk();

        $row = collect($response->json('rows'))->firstWhere('reference', 'KRS-REG-1');
        $this->assertSame('order', $row['type']);
        $this->assertSame(1000, $row['gross_sen']);
        $this->assertSame(100, $row['fee_sen']);
        $this->assertSame(900, $row['cost_sen']);
        $this->assertSame(100, $row['net_sen']);
        $this->assertSame('Digiflazz', $row['supplier']);
    }

    /**
     * Regression: `Order.platform_profit` is stamped at checkout time,
     * before the delivery outcome is known — a paid-but-undelivered
     * order must show net_sen=0 here (no ledger_entries row exists yet
     * for it), never the stale checkout-time column value, mirroring
     * ReportService::profitTotals()/DashboardService's own rule.
     */
    public function test_index_shows_zero_net_for_a_paid_but_undelivered_order(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'standard_selling_price' => 1000,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        Order::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-REG-UNDELIVERED',
            'customer_email' => 'buyer@example.com',
            'player_id' => '1',
            'game_id' => $game->id,
            'package_id' => $package->id,
            'supplier_id' => $supplier->id,
            'supplier_product_ref' => 'A',
            'cost_price' => 900,
            'standard_selling_price' => 1000,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100, // stamped at checkout time — not yet realized
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Pending->value,
            'paid_at' => now(),
        ]);
        // Deliberately no LedgerEntry row — creditProfit() only runs on delivery.

        $response = $this->getJson('/api/accounting/transactions')->assertOk();

        $row = collect($response->json('rows'))->firstWhere('reference', 'KRS-REG-UNDELIVERED');
        $this->assertSame(0, $row['net_sen'], 'net_sen must come from ledger_entries, never the checkout-time Order.platform_profit column');
    }

    public function test_index_excludes_a_test_order(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'standard_selling_price' => 1000,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        Order::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-REG-TEST',
            'customer_email' => 'buyer@example.com',
            'player_id' => '1',
            'game_id' => $game->id,
            'package_id' => $package->id,
            'supplier_id' => $supplier->id,
            'supplier_product_ref' => 'A',
            'cost_price' => 900,
            'standard_selling_price' => 1000,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
            'paid_at' => now(),
            'is_test' => true,
        ]);

        $response = $this->getJson('/api/accounting/transactions')->assertOk();

        $this->assertNull(collect($response->json('rows'))->firstWhere('reference', 'KRS-REG-TEST'));
    }

    public function test_index_includes_a_supplier_transfer_row(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        SupplierTransfer::query()->create([
            'supplier_id' => $supplier->id,
            'source_channel' => 'wise',
            'amount_myr_sent' => 100000,
            'fee_myr' => 250,
            'currency' => 'IDR',
            'amount_foreign_received' => '3700000.0000',
            'reference_no' => 'WISE-REG-1',
        ]);

        $response = $this->getJson('/api/accounting/transactions')->assertOk();

        $row = collect($response->json('rows'))->firstWhere('reference', 'WISE-REG-1');
        $this->assertSame('supplier_transfer', $row['type']);
        $this->assertSame(250, $row['fee_sen']);
        $this->assertSame(-100250, $row['net_sen']);
        $this->assertSame('3700000.0000', $row['amount_foreign']);
    }

    public function test_index_includes_a_supplier_refund_row_but_not_a_topup_or_drawdown(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id, 'type' => SupplierLedgerEntryType::Topup->value, 'amount' => '5000000.0000', 'currency' => 'IDR',
        ]);
        SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id, 'type' => SupplierLedgerEntryType::OrderDrawdown->value, 'amount' => '-15000.0000', 'currency' => 'IDR',
        ]);
        $refund = SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id, 'type' => SupplierLedgerEntryType::Refund->value, 'amount' => '15000.0000', 'currency' => 'IDR', 'reason' => 'test refund',
        ]);

        $rows = collect($this->getJson('/api/accounting/transactions')->assertOk()->json('rows'));

        $refundRow = $rows->firstWhere('reference', "Ledger entry #{$refund->id}");
        $this->assertSame('supplier_refund', $refundRow['type']);
        $this->assertSame('15000.0000', $refundRow['amount_foreign']);
        $this->assertSame(1, $rows->where('type', 'supplier_refund')->count());
        $this->assertSame(0, $rows->whereIn('type', ['supplier_topup', 'supplier_drawdown'])->count());
    }

    public function test_index_includes_a_path_b_voucher_but_not_a_standalone_one(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'standard_selling_price' => 1000,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        $order = Order::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-REG-2',
            'customer_email' => 'buyer@example.com',
            'player_id' => '1',
            'game_id' => $game->id,
            'package_id' => $package->id,
            'supplier_id' => $supplier->id,
            'supplier_product_ref' => 'A',
            'cost_price' => 900,
            'standard_selling_price' => 1000,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 0,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);
        $affiliateId = $this->primaryAffiliate()->id;
        Voucher::query()->create([
            'order_id' => $order->id, 'affiliate_id' => $affiliateId, 'code' => 'VC-REGPATHB', 'customer_email' => 'buyer@example.com',
            'amount' => 1000, 'remaining' => 1000, 'status' => 'active', 'reason' => 'delivery failed',
        ]);
        Voucher::query()->create([
            'affiliate_id' => $affiliateId, 'code' => 'VC-REGSTANDALONE', 'customer_email' => 'other@example.com',
            'amount' => 500, 'remaining' => 500, 'status' => 'active', 'reason' => 'goodwill',
        ]);

        $rows = collect($this->getJson('/api/accounting/transactions')->assertOk()->json('rows'));

        $pathB = $rows->firstWhere('reference', 'VC-REGPATHB');
        $this->assertSame('voucher_issued', $pathB['type']);
        $this->assertSame(-1000, $pathB['net_sen']);
        $this->assertNull($rows->firstWhere('reference', 'VC-REGSTANDALONE'));
    }

    public function test_export_streams_a_csv(): void
    {
        $this->actAsSuperAdmin();
        $supplier = $this->supplier();
        SupplierTransfer::query()->create([
            'supplier_id' => $supplier->id, 'source_channel' => 'wise', 'amount_myr_sent' => 100000,
            'currency' => 'IDR', 'amount_foreign_received' => '3700000.0000', 'reference_no' => 'WISE-CSV-1',
        ]);

        $response = $this->get('/api/accounting/transactions/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('WISE-CSV-1', $content);
        $this->assertStringContainsString('Date,Type,Reference', $content);
    }
}
