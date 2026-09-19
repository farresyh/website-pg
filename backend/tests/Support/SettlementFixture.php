<?php

namespace Tests\Support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * ADR-110 PR-B — builds a settlement `.xlsx` shaped exactly like a real
 * CHIP export (confirmed against the actual PekanGame CHIP account's
 * file this ADR's build session), so tests never need a committed
 * binary fixture.
 */
final class SettlementFixture
{
    /**
     * @param  array<int, array{transaction_id: string, reference: string, amount: string, fee: string, net: string, settled_on: string, acquirer?: string}>  $transactions
     */
    public static function build(
        string $dateRange,
        string $totalAmount,
        string $totalFee,
        string $totalNet,
        array $transactions,
        string $sheetName = 'FPX',
    ): string {
        $path = tempnam(sys_get_temp_dir(), 'settlement').'.xlsx';

        $writer = new Writer;
        $writer->openToFile($path);

        $writer->getCurrentSheet()->setName('Summary');
        $writer->addRow(Row::fromValues(['Settlement Date Range', $dateRange]));
        $writer->addRow(Row::fromValues(['Total Amount', 'MYR', $totalAmount]));
        $writer->addRow(Row::fromValues(['Total Fee', 'MYR', $totalFee]));
        $writer->addRow(Row::fromValues(['Total Net Amount', 'MYR', $totalNet]));

        $writer->addNewSheetAndMakeItCurrent()->setName($sheetName);
        $writer->addRow(Row::fromValues([
            'Transaction ID', 'Type', 'Status', 'Name', 'Acquirer', 'Payment Method', 'Currency',
            'Amount', 'Fee', 'Net Amount', 'Country', 'Related To ID', 'Description', 'Brand ID',
            'Paid At (MYT)', 'Settled On (MYT)', 'Reference', 'Customer Name', 'Customer Email', 'Customer Phone Number',
        ]));

        foreach ($transactions as $t) {
            $writer->addRow(Row::fromValues([
                $t['transaction_id'], 'purchase', 'paid', 'JW CORE VENTURE', $t['acquirer'] ?? 'fpx', 'fpx', 'MYR',
                $t['amount'], $t['fee'], $t['net'], 'MY', null, null, 'brand-uuid-123',
                $t['settled_on'], $t['settled_on'], $t['reference'], 'Test Buyer', 'buyer@example.com', null,
            ]));
        }

        $writer->close();

        return $path;
    }
}
