<?php

namespace Tests\Feature\Services\Order;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Fulfillment\OrderSettlementService;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrdersWorkbook;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;
use ZipArchive;

/**
 * ADR-108 2026-10-04 addendum, decision 4 — the 2-sheet orders workbook:
 * a formula-driven Summary over an Orders sheet.
 */
class OrdersWorkbookTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $overrides): Order
    {
        return Order::query()->create(array_merge(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'PG-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 1000,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
            'delivery_status' => DeliveryStatus::Delivered->value,
        ], $overrides));
    }

    /** @return array{orders: array<int, array<int, mixed>>, summaryXml: string, ordersXml: string, sheetNames: list<string>} */
    private function write(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'orders-test-').'.xlsx';
        app(OrdersWorkbook::class)->write(Order::query()->orderBy('id'), $path, 'All orders');

        $reader = new Reader;
        $reader->open($path);
        $sheetNames = [];
        $orders = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $sheetNames[] = $sheet->getName();
            if ($sheet->getName() === 'Orders') {
                foreach ($sheet->getRowIterator() as $row) {
                    $orders[] = $row->toArray();
                }
            }
        }
        $reader->close();

        $zip = new ZipArchive;
        $zip->open($path);
        $summaryXml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $ordersXml = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();
        unlink($path);

        return ['orders' => $orders, 'summaryXml' => $summaryXml, 'ordersXml' => $ordersXml, 'sheetNames' => $sheetNames];
    }

    private function column(array $orders, string $header): int
    {
        return array_search($header, $orders[0], true);
    }

    public function test_it_writes_a_summary_and_an_orders_sheet(): void
    {
        $this->order(['order_number' => 'PG-DELIVERED']);

        $book = $this->write();

        $this->assertSame(['Summary', 'Orders'], $book['sheetNames']);
        $this->assertSame('PG-DELIVERED', $book['orders'][1][0]);
    }

    public function test_orders_sheet_separates_earned_from_expected_and_shows_compensation(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'admin']);
        $delivered = $this->order(['order_number' => 'PG-DELIVERED', 'platform_profit' => 37]);
        app(LedgerService::class)->creditOrderProfit($delivered);
        app(LedgerService::class)->credit(LedgerOwnerType::Platform, null, -10, 'order_profit', 'order', $delivered->id, reason: 'correction');

        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);
        $refunded = $this->order([
            'order_number' => 'PG-REFUNDED', 'wallet_reseller_id' => $reseller->id, 'pricing_basis' => 'reseller-wallet',
            'delivery_status' => DeliveryStatus::Failed->value, 'transaction_fee' => 0, 'final_amount' => 1000, 'platform_profit' => 1001,
        ]);
        app(OrderSettlementService::class)->settle($refunded, $admin->id);

        $vouchered = $this->order(['order_number' => 'PG-VOUCHERED', 'delivery_status' => DeliveryStatus::Failed->value, 'voucher_discount' => 400, 'final_amount' => 700]);
        $x = Voucher::query()->create(['affiliate_id' => $vouchered->affiliate_id, 'code' => 'VC-X', 'customer_email' => 'buyer@example.com', 'amount' => 400, 'remaining' => 0, 'status' => 'exhausted', 'reason' => 't']);
        VoucherRedemption::query()->create(['voucher_id' => $x->id, 'order_id' => $vouchered->id, 'amount' => 400, 'status' => 'reserved']);
        app(OrderSettlementService::class)->settle($vouchered, $admin->id);

        $orders = $this->write()['orders'];
        $rows = collect(array_slice($orders, 1))->keyBy(0);
        $c = fn (string $h) => $this->column($orders, $h);

        // Delivered: earned is the corrected ledger figure; expected stays the plan.
        $this->assertEqualsWithDelta(0.27, $rows['PG-DELIVERED'][$c('Platform Profit Earned (RM)')], 0.0001);
        $this->assertEqualsWithDelta(0.37, $rows['PG-DELIVERED'][$c('Platform Profit Expected (RM)')], 0.0001);
        // Refunded: nothing earned (blank), the refund shown, funded by the wallet.
        $this->assertSame('', $rows['PG-REFUNDED'][$c('Platform Profit Earned (RM)')]);
        $this->assertEqualsWithDelta(10.00, $rows['PG-REFUNDED'][$c('Wallet Refund (RM)')], 0.0001);
        $this->assertSame('Reseller Wallet', $rows['PG-REFUNDED'][$c('Funding Source')]);
        // Vouchered: cash share issued as a voucher, the paid-with voucher restored.
        $this->assertEqualsWithDelta(6.00, $rows['PG-VOUCHERED'][$c('Voucher Issued (RM)')], 0.0001);
        $this->assertEqualsWithDelta(4.00, $rows['PG-VOUCHERED'][$c('Voucher Restored (RM)')], 0.0001);
        $this->assertSame('CHIP', $rows['PG-VOUCHERED'][$c('Funding Source')]);
    }

    public function test_summary_figures_are_formulas_over_the_orders_sheet(): void
    {
        $this->order(['order_number' => 'PG-1']);
        $this->order(['order_number' => 'PG-2', 'payment_status' => PaymentStatus::Failed->value, 'paid_at' => null]);

        $xml = $this->write()['summaryXml'];

        // Two orders → data rows 2..3. Paid-only totals, matching Reports.
        $this->assertStringContainsString('SUMIFS(Orders!$P$2:$P$3,Orders!$I$2:$I$3,"paid")', html_entity_decode($xml));
        $this->assertStringContainsString('SUM(Orders!$Y$2:$Y$3)', html_entity_decode($xml));
    }

    /** User-typed text must never become a formula in an admin's spreadsheet. */
    public function test_customer_text_starting_with_equals_is_written_as_text(): void
    {
        $this->order(['order_number' => 'PG-INJECT', 'customer_email' => '=HYPERLINK("http://evil.test","x")', 'player_id' => '=1+1']);

        $book = $this->write();
        $row = collect(array_slice($book['orders'], 1))->keyBy(0)['PG-INJECT'];

        $this->assertSame('=HYPERLINK("http://evil.test","x")', $row[$this->column($book['orders'], 'Customer Email')]);
        $this->assertStringNotContainsString('<f>HYPERLINK', $book['ordersXml']);
        $this->assertStringNotContainsString('<f>1+1', $book['ordersXml']);
    }
}
