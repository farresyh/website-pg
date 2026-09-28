<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Accounting\TransactionRegisterService;
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
        [$from, $to] = $this->rangeFromRequest($request);
        $type = $request->filled('type') ? $request->query('type') : null;
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        return response()->json($this->register->paginate($from, $to, $type, $page, $perPage));
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->rangeFromRequest($request);
        $type = $request->filled('type') ? $request->query('type') : null;
        $rows = $this->register->rows($from, $to, $type);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Type', 'Reference', 'Description', 'Supplier', 'Currency', 'Gross (RM)', 'Fee (RM)', 'Cost (RM)', 'Net (RM)', 'Amount (foreign)', 'Status']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['date'],
                    $row['type'],
                    $row['reference'],
                    $row['description'],
                    $row['supplier'] ?? '',
                    $row['currency'],
                    $row['gross_sen'] !== null ? number_format($row['gross_sen'] / 100, 2, '.', '') : '',
                    $row['fee_sen'] !== null ? number_format($row['fee_sen'] / 100, 2, '.', '') : '',
                    $row['cost_sen'] !== null ? number_format($row['cost_sen'] / 100, 2, '.', '') : '',
                    $row['net_sen'] !== null ? number_format($row['net_sen'] / 100, 2, '.', '') : '',
                    $row['amount_foreign'] ?? '',
                    $row['status'],
                ]);
            }

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
        $from = $request->filled('from') ? CarbonImmutable::parse($request->query('from'))->startOfDay() : null;
        $to = $request->filled('to') ? CarbonImmutable::parse($request->query('to'))->endOfDay() : null;

        return [$from, $to];
    }
}
