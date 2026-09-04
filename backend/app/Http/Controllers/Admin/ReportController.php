<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Services\Report\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RPT-1..3 (docs/prd.md §6.9). See ReportService's own doc comment for
 * the sales/profit definitions grilled and pinned 2026-08-26 — this
 * controller only resolves request params into the service's params,
 * no calculation lives here.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    /**
     * Minimal affiliate list for the Reports filter dropdown only — full
     * Affiliates Management CRUD (§6.7) is Phase 2, not built yet.
     */
    public function affiliates(): JsonResponse
    {
        return response()->json(
            Affiliate::query()->orderBy('business_name')->get(['id', 'business_name']),
        );
    }

    public function summary(Request $request): JsonResponse
    {
        [$from, $toExclusive] = $this->rangeFromRequest($request);

        return response()->json(
            $this->reports->summary($from, $toExclusive, $this->affiliateId($request)),
        );
    }

    public function trend(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 7);
        $days = in_array($days, [7, 14, 30], true) ? $days : 7;

        return response()->json([
            'days' => $this->reports->dailyTrend($days, $this->affiliateId($request)),
        ]);
    }

    public function dailyBreakdown(Request $request): JsonResponse
    {
        [$from, $toExclusive] = $this->rangeFromRequest($request);

        return response()->json([
            'days' => $this->reports->dailyBreakdown($from, $toExclusive, $this->affiliateId($request)),
        ]);
    }

    public function topGames(Request $request): JsonResponse
    {
        [$from, $toExclusive] = $this->rangeFromRequest($request);
        $limit = (int) $request->query('limit', 5);

        return response()->json([
            'games' => $this->reports->gameBreakdown($from, $toExclusive, $this->affiliateId($request), $limit),
        ]);
    }

    public function gameBreakdown(Request $request): JsonResponse
    {
        [$from, $toExclusive] = $this->rangeFromRequest($request);

        return response()->json([
            'games' => $this->reports->gameBreakdown($from, $toExclusive, $this->affiliateId($request)),
        ]);
    }

    public function paymentMethodBreakdown(Request $request): JsonResponse
    {
        [$from, $toExclusive] = $this->rangeFromRequest($request);

        return response()->json([
            'payment_methods' => $this->reports->paymentMethodBreakdown($from, $toExclusive, $this->affiliateId($request)),
        ]);
    }

    public function affiliateBreakdown(Request $request): JsonResponse
    {
        [$from, $toExclusive] = $this->rangeFromRequest($request);

        return response()->json([
            'affiliates' => $this->reports->affiliateBreakdown($from, $toExclusive, $this->affiliateId($request)),
        ]);
    }

    public function orderStatusFunnel(Request $request): JsonResponse
    {
        [$from, $toExclusive] = $this->rangeFromRequest($request);

        return response()->json(
            $this->reports->orderStatusFunnel($from, $toExclusive, $this->affiliateId($request)),
        );
    }

    public function membershipBreakdown(Request $request): JsonResponse
    {
        [$from, $toExclusive] = $this->rangeFromRequest($request);

        return response()->json(
            $this->reports->membershipBreakdown($from, $toExclusive, $this->affiliateId($request)),
        );
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        [$from, $toExclusive] = $this->rangeFromRequest($request);

        $affiliateId = $this->affiliateId($request);
        $rows = $this->reports->exportRows($from, $toExclusive, $affiliateId)->all();

        $format = $request->query('format', 'csv');
        $rangeLabel = $this->rangeLabel($request->integer('year') ?: null, $request->integer('month') ?: null);
        $affiliateLabel = $affiliateId ? Affiliate::query()->find($affiliateId)?->business_name : null;

        return $format === 'pdf'
            ? $this->exportPdf($rows, $rangeLabel, $affiliateLabel)
            : $this->exportCsv($rows, $rangeLabel);
    }

    private function affiliateId(Request $request): ?int
    {
        return $request->filled('affiliate_id') ? (int) $request->query('affiliate_id') : null;
    }

    private function rangeFromRequest(Request $request): array
    {
        return $this->reports->dateRangeForYearMonth(
            $request->integer('year') ?: null,
            $request->integer('month') ?: null,
        );
    }

    private function rangeLabel(?int $year, ?int $month): string
    {
        if ($year === null) {
            return 'All time';
        }

        return $month !== null ? sprintf('%04d-%02d', $year, $month) : (string) $year;
    }

    private function exportCsv(array $rows, string $rangeLabel): StreamedResponse
    {
        $filename = 'sales-report-'.str_replace(' ', '-', strtolower($rangeLabel)).'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Order #', 'Paid At', 'Customer', 'Affiliate', 'Sales (RM)', 'Platform Profit (RM)', 'Affiliate Profit (RM)']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['order_number'],
                    $row['paid_at'],
                    $row['customer_email'],
                    $row['affiliate_name'] ?? '',
                    number_format($row['final_amount'] / 100, 2, '.', ''),
                    number_format($row['platform_profit'] / 100, 2, '.', ''),
                    number_format($row['affiliate_profit'] / 100, 2, '.', ''),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function exportPdf(array $rows, string $rangeLabel, ?string $affiliateLabel): Response
    {
        $filename = 'sales-report-'.str_replace(' ', '-', strtolower($rangeLabel)).'.pdf';

        return Pdf::loadView('reports.export-pdf', [
            'rows' => $rows,
            'rangeLabel' => $rangeLabel,
            'affiliateLabel' => $affiliateLabel,
        ])->download($filename);
    }
}
