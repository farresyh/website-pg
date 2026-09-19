<?php

namespace App\Services\Accounting;

use Illuminate\Support\Carbon;
use OpenSpout\Reader\SheetInterface;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * ADR-110 PR-B, fills ADR-083 decision 7 — parses a CHIP settlement
 * `.xlsx` exactly the way CHIP's own dashboard export is shaped
 * (confirmed against a real file from the PekanGame CHIP account,
 * `settlements-20260919025821.xlsx`, this ADR's own build session):
 * a "Summary" sheet (label/value pairs, not a header row) plus one
 * sheet per acquirer (its first row is the header). Keyed on column
 * NAME, not position — an acquirer sheet gaining/reordering columns,
 * or a brand-new acquirer sheet (DuitNow QR, `fpx_b2b1`) whenever
 * activated, needs no parser change; only the required column set
 * (below) must still be present.
 */
final class SettlementFileParser
{
    private const REQUIRED_COLUMNS = ['Transaction ID', 'Reference', 'Amount', 'Fee', 'Net Amount', 'Settled On (MYT)'];

    public function parse(string $filePath): ParsedSettlementFile
    {
        $reader = new Reader;
        $reader->open($filePath);

        $summary = null;
        $transactions = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            if (strcasecmp($sheet->getName(), 'Summary') === 0) {
                $summary = $this->parseSummarySheet($sheet);

                continue;
            }

            array_push($transactions, ...$this->parseAcquirerSheet($sheet));
        }

        $reader->close();

        if ($summary === null) {
            throw new RuntimeException('Settlement file has no "Summary" sheet — is this a real CHIP export?');
        }

        return new ParsedSettlementFile(
            dateFrom: $summary['date_from'],
            dateTo: $summary['date_to'],
            fileGrossSen: $summary['gross_sen'],
            fileFeeSen: $summary['fee_sen'],
            fileNetSen: $summary['net_sen'],
            transactions: $transactions,
        );
    }

    /**
     * @return array{date_from: Carbon, date_to: Carbon, gross_sen: int, fee_sen: int, net_sen: int}
     */
    private function parseSummarySheet(SheetInterface $sheet): array
    {
        $dateFrom = null;
        $dateTo = null;
        $gross = null;
        $fee = null;
        $net = null;

        foreach ($sheet->getRowIterator() as $row) {
            $cells = $row->toArray();
            $label = trim((string) ($cells[0] ?? ''));

            if ($label === 'Settlement Date Range') {
                [$from, $to] = array_pad(explode(' to ', (string) ($cells[1] ?? '')), 2, null);
                $dateFrom = Carbon::parse(trim((string) $from));
                $dateTo = Carbon::parse(trim((string) ($to ?? $from)));
            } elseif ($label === 'Total Amount') {
                $gross = $this->toSen($cells[2] ?? null);
            } elseif ($label === 'Total Fee') {
                $fee = $this->toSen($cells[2] ?? null);
            } elseif ($label === 'Total Net Amount') {
                $net = $this->toSen($cells[2] ?? null);
            }
        }

        if ($dateFrom === null || $dateTo === null || $gross === null || $fee === null || $net === null) {
            throw new RuntimeException('Settlement file\'s "Summary" sheet is missing an expected row (Settlement Date Range / Total Amount / Total Fee / Total Net Amount).');
        }

        return ['date_from' => $dateFrom, 'date_to' => $dateTo, 'gross_sen' => $gross, 'fee_sen' => $fee, 'net_sen' => $net];
    }

    /**
     * @return ParsedSettlementTransaction[]
     */
    private function parseAcquirerSheet(SheetInterface $sheet): array
    {
        $headers = null;
        $transactions = [];

        foreach ($sheet->getRowIterator() as $row) {
            $cells = $row->toArray();

            if ($headers === null) {
                $headers = array_map(fn ($cell) => trim((string) $cell), $cells);

                $missing = array_diff(self::REQUIRED_COLUMNS, $headers);
                if ($missing !== []) {
                    throw new RuntimeException(sprintf(
                        'Settlement sheet "%s" is missing required column(s): %s — has CHIP\'s export format changed?',
                        $sheet->getName(),
                        implode(', ', $missing),
                    ));
                }

                continue;
            }

            $byName = array_combine($headers, array_pad(array_slice($cells, 0, count($headers)), count($headers), null));

            $transactionId = trim((string) ($byName['Transaction ID'] ?? ''));
            if ($transactionId === '') {
                continue; // a stray blank row — CHIP's own export sometimes trails one
            }

            $transactions[] = new ParsedSettlementTransaction(
                transactionId: $transactionId,
                referenceCode: filled($byName['Reference'] ?? null) ? trim((string) $byName['Reference']) : null,
                amountSen: $this->toSen($byName['Amount']),
                feeSen: $this->toSen($byName['Fee']),
                netAmountSen: $this->toSen($byName['Net Amount']),
                acquirer: filled($byName['Acquirer'] ?? null) ? (string) $byName['Acquirer'] : $sheet->getName(),
                settledOn: Carbon::parse((string) $byName['Settled On (MYT)']),
            );
        }

        return $transactions;
    }

    private function toSen(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }
}
