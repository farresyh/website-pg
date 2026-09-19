<?php

namespace Tests\Unit\Services\Accounting;

use App\Services\Accounting\SettlementFileParser;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Support\SettlementFixture;
use Tests\TestCase;

/**
 * ADR-110 PR-B — confirms the parser reads a settlement `.xlsx` shaped
 * exactly like the real CHIP export this ADR's build session verified
 * (`settlements-20260919025821.xlsx`, one Summary sheet + one sheet
 * per acquirer, keyed on column name).
 */
class SettlementFileParserTest extends TestCase
{
    public function test_parses_the_summary_sheet_into_sen(): void
    {
        $path = SettlementFixture::build(
            dateRange: '2026-09-07 to 2026-09-07',
            totalAmount: '11.00',
            totalFee: '1.00',
            totalNet: '10.00',
            transactions: [],
        );

        $parsed = (new SettlementFileParser)->parse($path);

        $this->assertSame('2026-09-07', $parsed->dateFrom->toDateString());
        $this->assertSame('2026-09-07', $parsed->dateTo->toDateString());
        $this->assertSame(1100, $parsed->fileGrossSen);
        $this->assertSame(100, $parsed->fileFeeSen);
        $this->assertSame(1000, $parsed->fileNetSen);

        unlink($path);
    }

    public function test_parses_a_date_range_spanning_multiple_days(): void
    {
        $path = SettlementFixture::build('2026-09-01 to 2026-09-07', '0.00', '0.00', '0.00', []);

        $parsed = (new SettlementFileParser)->parse($path);

        $this->assertSame('2026-09-01', $parsed->dateFrom->toDateString());
        $this->assertSame('2026-09-07', $parsed->dateTo->toDateString());

        unlink($path);
    }

    public function test_parses_every_transaction_row_in_the_acquirer_sheet(): void
    {
        $path = SettlementFixture::build('2026-09-07 to 2026-09-07', '11.00', '1.00', '10.00', [
            ['transaction_id' => 'c7c6fec2-78f2-49f3-9220-d054290f1186', 'reference' => 'PG-ABC123', 'amount' => '11.00', 'fee' => '1.00', 'net' => '10.00', 'settled_on' => '2026-09-07 12:32'],
        ]);

        $parsed = (new SettlementFileParser)->parse($path);

        $this->assertCount(1, $parsed->transactions);
        $tx = $parsed->transactions[0];
        $this->assertSame('c7c6fec2-78f2-49f3-9220-d054290f1186', $tx->transactionId);
        $this->assertSame('PG-ABC123', $tx->referenceCode);
        $this->assertSame(1100, $tx->amountSen);
        $this->assertSame(100, $tx->feeSen);
        $this->assertSame(1000, $tx->netAmountSen);
        $this->assertSame('fpx', $tx->acquirer);
        $this->assertSame('2026-09-07', $tx->settledOn->toDateString());

        unlink($path);
    }

    /**
     * A new payment method (DuitNow QR, `fpx_b2b1`) gets its own sheet
     * with a different name — the parser must not hardcode "FPX".
     */
    public function test_reads_a_differently_named_acquirer_sheet(): void
    {
        $path = SettlementFixture::build(
            '2026-09-07 to 2026-09-07', '5.00', '0.50', '4.50',
            [['transaction_id' => 'tx-1', 'reference' => 'PG-XYZ', 'amount' => '5.00', 'fee' => '0.50', 'net' => '4.50', 'settled_on' => '2026-09-07 09:00', 'acquirer' => 'duitnow_qr']],
            sheetName: 'DuitNow QR',
        );

        $parsed = (new SettlementFileParser)->parse($path);

        $this->assertCount(1, $parsed->transactions);
        $this->assertSame('duitnow_qr', $parsed->transactions[0]->acquirer);

        unlink($path);
    }

    public function test_throws_when_a_required_column_is_missing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'settlement').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Summary');
        $writer->addRow(Row::fromValues(['Settlement Date Range', '2026-09-07 to 2026-09-07']));
        $writer->addRow(Row::fromValues(['Total Amount', 'MYR', '1.00']));
        $writer->addRow(Row::fromValues(['Total Fee', 'MYR', '0.00']));
        $writer->addRow(Row::fromValues(['Total Net Amount', 'MYR', '1.00']));
        $writer->addNewSheetAndMakeItCurrent()->setName('FPX');
        // Missing "Fee" column entirely — simulates a CHIP export format change.
        $writer->addRow(Row::fromValues(['Transaction ID', 'Reference', 'Amount', 'Net Amount', 'Settled On (MYT)']));
        $writer->close();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing required column/');

        (new SettlementFileParser)->parse($path);

        unlink($path);
    }
}
