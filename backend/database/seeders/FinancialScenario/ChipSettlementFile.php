<?php

namespace Database\Seeders\FinancialScenario;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * ADR-127 decision 12: a CHIP settlement export in the shape
 * SettlementFileParser reads (a Summary sheet plus one acquirer sheet),
 * so the scenario's settlements go through the real ingest.
 */
final class ChipSettlementFile
{
    /**
     * @param  list<array{id: string, reference: string, amount_sen: int, fee_sen: int}>  $transactions
     */
    public static function write(string $from, string $to, string $settledOn, array $transactions): string
    {
        $path = tempnam(sys_get_temp_dir(), 'scn-settlement').'.xlsx';
        $rm = fn (int $sen) => number_format($sen / 100, 2, '.', '');
        $gross = array_sum(array_column($transactions, 'amount_sen'));
        $fee = array_sum(array_column($transactions, 'fee_sen'));

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Summary');
        $writer->addRow(Row::fromValues(['Settlement Date Range', "{$from} to {$to}"]));
        $writer->addRow(Row::fromValues(['Total Amount', 'MYR', $rm($gross)]));
        $writer->addRow(Row::fromValues(['Total Fee', 'MYR', $rm($fee)]));
        $writer->addRow(Row::fromValues(['Total Net Amount', 'MYR', $rm($gross - $fee)]));

        $writer->addNewSheetAndMakeItCurrent()->setName('FPX');
        $writer->addRow(Row::fromValues([
            'Transaction ID', 'Type', 'Status', 'Name', 'Acquirer', 'Payment Method', 'Currency',
            'Amount', 'Fee', 'Net Amount', 'Country', 'Related To ID', 'Description', 'Brand ID',
            'Paid At (MYT)', 'Settled On (MYT)', 'Reference', 'Customer Name', 'Customer Email', 'Customer Phone Number',
        ]));
        foreach ($transactions as $t) {
            $writer->addRow(Row::fromValues([
                $t['id'], 'purchase', 'paid', 'Scenario', 'fpx', 'fpx', 'MYR',
                $rm($t['amount_sen']), $rm($t['fee_sen']), $rm($t['amount_sen'] - $t['fee_sen']), 'MY', null, null, 'scenario-brand',
                $settledOn, $settledOn, $t['reference'], 'Scenario Buyer', 'buyer@scenario.test', null,
            ]));
        }
        $writer->close();

        return $path;
    }
}
