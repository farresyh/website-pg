<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Accounting\TransactionRegisterService;
use App\Services\Report\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-083 decision 9 (+ 2026-09-28 addendum) — read-only, "nothing is
 * ever lost" view across the five money-moving row kinds
 * `TransactionRegisterService` projects. No calculation lives here,
 * same posture as `ReportController`.
 */
class TransactionRegisterController extends Controller
{
    public function __construct(private readonly TransactionRegisterService $register) {}

    /** `type` filters to one row kind; `page`/`per_page` back real backend pagination (2026-09-28 addendum — was unpaginated before). */
    public function index(Request $request): JsonResponse
    {
        [$from, $toExclusive] = $this->rangeFromRequest($request);
        $type = $request->filled('type') ? $request->query('type') : null;
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        return response()->json($this->register->paginate($from, $toExclusive, $type, $page, $perPage));
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $toExclusive] = $this->rangeFromRequest($request);
        $type = $request->filled('type') ? $request->query('type') : null;
        $rows = $this->register->rows($from, $toExclusive, $type);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Type', 'Reference', 'Description', 'Supplier', 'Currency', 'Gross (RM)', 'Fee (RM)', 'Cost (RM)', 'Net (RM)', 'Amount (foreign)', 'Status', 'Funding source']);

            foreach ($rows as $row) {
                // 2026-09-30 audit fix: a voided row already carries a
                // `Status` column, but a spreadsheet reader scanning by
                // eye (not filtering columns) can still miss it — the
                // admin UI itself already strikes voided rows through in
                // red (see `admin/.../transactions/page.tsx`), CSV has no
                // equivalent, so the reference itself gets the same
                // signal a plain-text export can actually carry.
                $reference = $row['status'] === 'voided' ? '[VOIDED] '.$row['reference'] : $row['reference'];

                fputcsv($out, [
                    $row['date'],
                    $row['type'],
                    $reference,
                    $row['description'],
                    $row['supplier'] ?? '',
                    $row['currency'],
                    $row['gross_sen'] !== null ? number_format($row['gross_sen'] / 100, 2, '.', '') : '',
                    $row['fee_sen'] !== null ? number_format($row['fee_sen'] / 100, 2, '.', '') : '',
                    $row['cost_sen'] !== null ? number_format($row['cost_sen'] / 100, 2, '.', '') : '',
                    $row['net_sen'] !== null ? number_format($row['net_sen'] / 100, 2, '.', '') : '',
                    $row['amount_foreign'] ?? '',
                    $row['status'],
                    $row['funding_source'] ?? '',
                ]);
            }

            // 2026-09-30 audit addendum (Bucket C, decision 4): a voided
            // row keeps its real original Net figure (deliberate —
            // "never rewrite history in place"), but its correction is
            // FX-only, never carrying an MYR figure — so a naive sum of
            // the Net column across a period spanning a void won't
            // reconcile to a real bank statement. Rather than a prose
            // note only the in-app UI would show (a CSV reader in Excel
            // would never see it), this computed footer row gives the
            // one number that actually does reconcile.
            $totalExcludingVoided = collect($rows)->where('status', '!=', 'voided')->sum('net_sen');
            fputcsv($out, ['', '', '', 'TOTAL Net (excluding voided rows)', '', '', '', '', '', number_format($totalExcludingVoided / 100, 2, '.', ''), '', '', '']);

            fclose($out);
        }, 'transaction-register.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * `from`/`to` are plain `Y-m-d` query params, both optional — an
     * absent bound means "no limit on that side", never "today".
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function rangeFromRequest(Request $request): array
    {
        return app(ReportService::class)->dateRangeFromDates(self::klDate($request->query('from')), self::klDate($request->query('to')));
    }

    /**
     * Item 63 (2026-10-04): `from`/`to` are KL calendar dates — the same
     * day bounds Reports and Orders use (`ReportService::dateRangeFromDates()`,
     * exclusive upper bound). They used to be parsed as UTC days, cutting
     * at 08:00 KL. A malformed value means "no bound".
     */
    private static function klDate(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
