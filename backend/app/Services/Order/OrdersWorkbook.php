<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Services\Report\ReportService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * ADR-108 2026-10-04 addendum, decision 4 — the admin orders export: a
 * "Summary" sheet whose every figure is an Excel formula over the
 * "Orders" sheet (so an accountant can click any total and see what is
 * added and subtracted), plus balance checks and a column dictionary.
 *
 * Money is written in RM as numbers. Customer-typed text is always a
 * StringCell — never Cell::fromValue(), which turns a leading "=" into a
 * live formula in the admin's spreadsheet.
 *
 * Profit columns: "Earned" is the ledger (Order::earnedProfitsFor());
 * "Expected" is the order's own plan columns. Totals count paid orders
 * only, like Reports.
 */
final class OrdersWorkbook
{
    private const MONEY = '#,##0.00';

    /**
     * Column definitions in sheet order — header, how to fill the cell,
     * and its dictionary entry. Formulas find a column by its header, so
     * reordering or adding a column never breaks the Summary.
     *
     * @return list<array{header: string, value: Closure(Order, array<string, mixed>): mixed, money?: bool, meaning: string, use: string}>
     */
    private function columns(): array
    {
        $rm = fn (?int $sen): ?float => $sen === null ? null : $sen / 100;
        $kl = fn ($at) => $at?->copy()->setTimezone(ReportService::TIMEZONE)->format('Y-m-d H:i');

        return [
            ['header' => 'Order #', 'value' => fn (Order $o) => $o->order_number, 'meaning' => 'Order number.', 'use' => 'Reference'],
            ['header' => 'Created (KL)', 'value' => fn (Order $o) => $kl($o->created_at), 'meaning' => 'When the order was placed, Kuala Lumpur time. The export\'s date filter uses this.', 'use' => 'Reference'],
            ['header' => 'Paid At (KL)', 'value' => fn (Order $o) => $kl($o->paid_at), 'meaning' => 'When payment was confirmed. Blank if never paid. Reports groups by this date.', 'use' => 'Reference'],
            ['header' => 'Customer Email', 'value' => fn (Order $o) => $o->customer_email, 'meaning' => 'Buyer email as typed at checkout.', 'use' => 'Reference'],
            ['header' => 'Game', 'value' => fn (Order $o) => $o->game?->name, 'meaning' => 'Game.', 'use' => 'Reference'],
            ['header' => 'Package', 'value' => fn (Order $o) => $o->package?->name, 'meaning' => 'Package bought.', 'use' => 'Reference'],
            ['header' => 'Source', 'value' => fn (Order $o) => $o->walletReseller
                ? 'Reseller: '.$o->walletReseller->business_name
                : ($o->affiliate && ! $o->affiliate->is_primary ? $o->affiliate->business_name : 'Direct'),
                'meaning' => 'Where the order came from: Direct storefront, an affiliate brand, or a reseller.', 'use' => 'Reference'],
            ['header' => 'Funding Source', 'value' => fn (Order $o) => $o->wallet_reseller_id !== null ? 'Reseller Wallet' : 'CHIP',
                'meaning' => 'CHIP = new money paid in. Reseller Wallet = spent from a balance the reseller already topped up (that money arrived at top-up, not here).', 'use' => 'Splits cash from wallet spend'],
            ['header' => 'Payment Status', 'value' => fn (Order $o) => $o->payment_status->value, 'meaning' => 'paid / pending / failed. Only "paid" rows count in the Summary totals.', 'use' => 'Filter for totals'],
            ['header' => 'Delivery Status', 'value' => fn (Order $o) => $o->delivery_status->value, 'meaning' => 'delivered, failed, partially_delivered, needs_review, pending, processing, not_started.', 'use' => 'Reference'],
            ['header' => 'Pricing Basis', 'value' => fn (Order $o) => $o->pricing_basis?->value, 'meaning' => 'standard, member, affiliate (wholesale tier) or reseller-wallet.', 'use' => 'Reference'],
            ['header' => 'Standard/Normal Selling Price (RM)', 'value' => fn (Order $o) => $rm($o->standard_selling_price ?? $o->normal_selling_price), 'money' => true, 'meaning' => 'The public list price at checkout, before any member/affiliate/reseller pricing.', 'use' => 'Information only'],
            ['header' => 'Selling Price (RM)', 'value' => fn (Order $o) => $rm($o->selling_price), 'money' => true, 'meaning' => 'The price this buyer was charged for the goods, before voucher and fee.', 'use' => 'Price check'],
            ['header' => 'Voucher Discount (RM)', 'value' => fn (Order $o) => $rm((int) $o->voucher_discount), 'money' => true, 'meaning' => 'Paid with an existing voucher (store credit already booked when that voucher was issued).', 'use' => 'Price check'],
            ['header' => 'Transaction Fee (RM)', 'value' => fn (Order $o) => $rm($o->transaction_fee), 'money' => true, 'meaning' => 'Payment fee charged to the buyer and passed to CHIP.', 'use' => 'Information only'],
            ['header' => 'Final Amount (RM)', 'value' => fn (Order $o) => $rm($o->final_amount), 'money' => true, 'meaning' => 'What the buyer actually paid: Selling Price − Voucher Discount + Transaction Fee.', 'use' => 'Gross Sales'],
            ['header' => 'Wallet Refund (RM)', 'value' => fn (Order $o, array $x) => $rm($x['wallet_refund']), 'money' => true, 'meaning' => 'Credited back to a reseller\'s wallet (all of it for a failed order, the undelivered share for a partial one).', 'use' => 'Subtracted from Gross Sales'],
            ['header' => 'Voucher Issued (RM)', 'value' => fn (Order $o) => $rm($o->voucher?->amount), 'money' => true, 'meaning' => 'Store-credit voucher issued to the buyer as compensation. A liability until spent; not subtracted from sales (same as Reports).', 'use' => 'Information only'],
            ['header' => 'Voucher Restored (RM)', 'value' => fn (Order $o) => $rm($o->voucherRedemption?->restored_amount), 'money' => true, 'meaning' => 'Balance given back to the voucher this order was paid with.', 'use' => 'Information only'],
            ['header' => 'Cost Price (RM)', 'value' => fn (Order $o) => $rm($o->effectiveCostPriceSen()), 'money' => true, 'meaning' => 'Supplier cost that produced the profit figures. See Cost Basis.', 'use' => 'Information only'],
            ['header' => 'Cost Basis', 'value' => fn (Order $o) => ucfirst($o->costBasis()), 'meaning' => 'Real = the supplier\'s actual charge; Mixed = a combo with some real legs; Estimated = catalog cost (not delivered yet, or real-cost tracking off).', 'use' => 'Reference'],
            ['header' => 'Member Markup %', 'value' => fn (Order $o) => $o->markup_percent !== null ? (float) $o->markup_percent : null, 'meaning' => 'Markup frozen on a member order.', 'use' => 'Reference'],
            ['header' => 'Affiliate Markup %', 'value' => fn (Order $o) => $o->affiliate_markup_pct !== null ? (float) $o->affiliate_markup_pct : null, 'meaning' => 'The affiliate brand\'s own markup on top.', 'use' => 'Reference'],
            ['header' => 'Wholesale Markup %', 'value' => fn (Order $o) => $o->wholesale_markup_pct !== null ? (float) $o->wholesale_markup_pct : null, 'meaning' => 'The reseller/affiliate wholesale tier markup frozen on the order.', 'use' => 'Reference'],
            ['header' => 'Platform Profit Earned (RM)', 'value' => fn (Order $o, array $x) => $rm($x['earned']['platform'] ?? null), 'money' => true, 'meaning' => 'Profit actually credited to the platform ledger. Blank = earned nothing (failed, refunded, unpaid or not delivered yet).', 'use' => 'Platform Profit Earned'],
            ['header' => 'Affiliate Profit Earned (RM)', 'value' => fn (Order $o, array $x) => $rm($x['earned']['affiliate'] ?? null), 'money' => true, 'meaning' => 'Commission actually credited to the affiliate.', 'use' => 'Affiliate Profit Earned'],
            ['header' => 'Platform Profit Expected (RM)', 'value' => fn (Order $o) => $rm($o->platform_profit), 'money' => true, 'meaning' => 'The profit planned at checkout (updated by a resend or real-cost check). Not money earned.', 'use' => 'Expected, not earned'],
            ['header' => 'Affiliate Profit Expected (RM)', 'value' => fn (Order $o) => $rm($o->affiliate_profit), 'money' => true, 'meaning' => 'The affiliate commission planned at checkout.', 'use' => 'Information only'],
        ];
    }

    /**
     * @param  Builder<Order>  $query  already filtered and ordered
     */
    public function write(Builder $query, string $path, string $scope): void
    {
        $columns = $this->columns();
        $letters = $this->letters($columns);
        $rowCount = (clone $query)->count();
        $last = $rowCount + 1;
        $range = fn (string $header): string => "Orders!\${$letters[$header]}\$2:\${$letters[$header]}\${$last}";
        $checkLetter = $this->letter(count($columns));

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Summary');
        $this->writeSummary($writer, $range, $scope, $rowCount, "Orders!\${$checkLetter}\$2:\${$checkLetter}\${$last}", $columns);

        $writer->addNewSheetAndMakeItCurrent()->setName('Orders');
        $bold = (new Style)->withFontBold(true);
        $money = (new Style)->withFormat(self::MONEY);
        $writer->addRow(new Row([
            ...array_map(fn (array $c) => new StringCell($c['header'], $bold), $columns),
            new StringCell('Price Check (RM)', $bold),
        ]));

        $rowNumber = 1;
        $query->with([
            'game:id,name', 'package:id,name', 'affiliate:id,business_name,is_primary', 'walletReseller:id,business_name',
            'deliveryLegs.componentPackage:id,cost_price', 'voucher:id,order_id,amount', 'voucherRedemption',
        ])->chunk(500, function ($orders) use ($writer, $columns, $letters, $money, &$rowNumber) {
            $earned = Order::earnedProfitsFor($orders);
            $refunds = Order::walletRefundEntriesFor($orders);

            foreach ($orders as $order) {
                $rowNumber++;
                $extra = ['earned' => $earned[$order->id] ?? null, 'wallet_refund' => ($refunds[$order->id] ?? null)?->amount];
                $cells = array_map(fn (array $c) => $this->cell($c['value']($order, $extra), $c['money'] ?? false ? $money : null), $columns);
                $l = fn (string $header): string => $letters[$header].$rowNumber;
                // Selling − Voucher + Fee − Final: 0 on every consistent row.
                $cells[] = new FormulaCell(
                    "={$l('Selling Price (RM)')}-{$l('Voucher Discount (RM)')}+{$l('Transaction Fee (RM)')}-{$l('Final Amount (RM)')}",
                    null,
                    $money,
                );
                $writer->addRow(new Row($cells));
            }
        });

        $writer->close();
    }

    private function writeSummary(Writer $writer, Closure $range, string $scope, int $rowCount, string $checkRange, array $columns): void
    {
        $bold = (new Style)->withFontBold(true);
        $title = (new Style)->withFontBold(true)->withFontSize(14);
        $money = (new Style)->withFormat(self::MONEY);
        $percent = (new Style)->withFormat('0.00%');
        $paid = fn (string $header, string $extra = ''): string => "SUMIFS({$range($header)},{$range('Payment Status')},\"paid\"{$extra})";

        // Every row goes through $add, which returns its sheet row number,
        // so a figure that refers to another figure uses that figure's real
        // cell — inserting a row never silently shifts a formula.
        $rowNumber = 0;
        $add = function (Row $row) use ($writer, &$rowNumber): int {
            $writer->addRow($row);

            return ++$rowNumber;
        };
        $text = fn (string ...$values) => $add(new Row(array_map(fn (string $v) => new StringCell($v), $values)));
        $heading = fn (string ...$values) => $add(new Row(array_map(fn (string $v) => new StringCell($v, $bold), $values)));
        $figure = fn (string $label, string $formula, string $how, ?Style $style = null): string => 'B'.$add(new Row([
            new StringCell($label), new FormulaCell("={$formula}", null, $style ?? $money), new StringCell($how),
        ]));
        $check = fn (string $label, string $count, string $ifNot) => $add(new Row([
            new StringCell($label), new FormulaCell("={$count}", null),
            new FormulaCell("=IF({$count}=0,\"OK\",\"CHECK\")", null), new StringCell($ifNot),
        ]));

        $add(new Row([new StringCell('Orders export — how to read this workbook', $title)]));
        $text('Scope', $scope);
        $text('Generated (KL)', now(ReportService::TIMEZONE)->format('Y-m-d H:i'));
        $text('Orders in this export', (string) $rowCount);
        $text('Every figure below is a formula over the "Orders" sheet — click a cell to see exactly what is added and subtracted. Totals count paid orders only.');
        $add(new Row([]));

        $heading('A. Key figures', 'Amount (RM)', 'How it is calculated');
        $gross = $figure('Gross Sales', $paid('Final Amount (RM)'), 'Sum of Final Amount on paid orders.');
        $refunded = $figure('− Refunded to Wallet', $paid('Wallet Refund (RM)'), 'Money credited back to reseller wallets.');
        $net = $figure('= Net Sales', "{$gross}-{$refunded}", 'Gross Sales minus wallet refunds. Matches Reports "Total Sales".');
        $platform = $figure('Platform Profit Earned', "SUM({$range('Platform Profit Earned (RM)')})", 'Profit actually credited to the platform. Matches Reports "Platform Profit".');
        $figure('Affiliate Profit Earned', "SUM({$range('Affiliate Profit Earned (RM)')})", 'Commission actually credited to affiliates.');
        $figure('Margin %', "IF({$net}=0,0,{$platform}/{$net})", 'Platform Profit Earned ÷ Net Sales.', $percent);
        $add(new Row([]));

        $heading('Information', 'Amount (RM)', 'Notes');
        $figure('Paid via CHIP', $paid('Final Amount (RM)', ",{$range('Funding Source')},\"CHIP\""), 'New money received through CHIP.');
        $figure('Paid from Reseller Wallets', $paid('Final Amount (RM)', ",{$range('Funding Source')},\"Reseller Wallet\""), 'Spent from wallet balances — that money arrived at top-up, so do not add it to cash again.');
        $figure('CHIP fees collected', $paid('Transaction Fee (RM)'), 'Charged to buyers and passed on to CHIP.');
        $figure('Vouchers issued as compensation', "SUM({$range('Voucher Issued (RM)')})", 'Store credit owed to buyers until they spend it (a liability). Not subtracted from sales, same as Reports.');
        $figure('Voucher balances restored', "SUM({$range('Voucher Restored (RM)')})", 'Given back to vouchers that orders were paid with.');
        $figure('Profit expected but not earned', $paid('Platform Profit Expected (RM)')."-{$platform}", 'Planned profit on paid orders minus what was earned (failed, refunded, partial or pending). Unpaid orders are abandoned checkouts and are excluded.');
        $add(new Row([]));

        $heading('B. Balance checks', 'Result', 'Status', 'If not OK');
        $check(
            'Rows where Selling − Voucher + Fee ≠ Final Amount',
            "COUNTIF({$checkRange},\">0.004\")+COUNTIF({$checkRange},\"<-0.004\")",
            'Filter the Orders sheet\'s "Price Check" column for non-zero rows.',
        );
        $check(
            'Earned profit on an order that was not delivered',
            "COUNTIFS({$range('Platform Profit Earned (RM)')},\"<>\",{$range('Delivery Status')},\"<>delivered\",{$range('Delivery Status')},\"<>partially_delivered\")",
            'Profit should only be earned on delivered (or settled partly delivered) orders — report the order numbers.',
        );
        $text('Compare with Reports', 'Open Reports for the same dates: Net Sales and Platform Profit Earned should match.', '', 'Small differences can come only from an order created before midnight KL and paid after it — this workbook selects by created date, Reports by paid date.');
        $add(new Row([]));

        $heading('C. Column dictionary (Orders sheet)', 'Meaning', 'Used for');
        foreach ($columns as $column) {
            $text($column['header'], $column['meaning'], $column['use']);
        }
        $text('Price Check (RM)', 'Selling Price − Voucher Discount + Transaction Fee − Final Amount. Always 0.', 'Balance check');

        $writer->getCurrentSheet()->setColumnWidth(42, 1);
        $writer->getCurrentSheet()->setColumnWidth(18, 2);
        $writer->getCurrentSheet()->setColumnWidth(90, 3);
    }

    private function cell(mixed $value, ?Style $moneyStyle): Cell
    {
        return match (true) {
            $value === null || $value === '' => new EmptyCell(null),
            is_int($value), is_float($value) => new NumericCell($value, $moneyStyle),
            default => new StringCell((string) $value),
        };
    }

    /** @return array<string, string> header => column letter */
    private function letters(array $columns): array
    {
        $letters = [];
        foreach ($columns as $i => $column) {
            $letters[$column['header']] = $this->letter($i);
        }

        return $letters;
    }

    private function letter(int $index): string
    {
        $letter = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letter = chr(65 + ($n - 1) % 26).$letter;
        }

        return $letter;
    }
}
