<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Services\Report\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RPT-1..3 (docs/prd.md §6.9). See ReportService's own doc comment for
 * the sales/profit definitions grilled and pinned 2026-08-26 — this
 * controller only resolves request params into the service's params,
 * no calculation lives here.
 *
 * ADR-086 filter-unification follow-up (2026-09-11): the old separate
 * Year/Month picker is gone — every tab, `?from=`/`?to=` (KL calendar
 * dates, 'YYYY-MM-DD', both optional), resolved by `rangeFromRequest()`.
 * The trend chart alone gets `trendRangeFromRequest()`: "All time" runs
 * from the first paid order to today (ADR-104 R17), since
 * `ReportService::dailyTrend()` zero-fills a concrete range.
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

    /**
     * ADR-104 R12 — `?compare=previous` (same-length range immediately
     * before) or `?compare=month_to_date` (same days of last month). The
     * page picks the mode from its preset: dates alone can't tell "This
     * month" from a "Last 7 days" that happens to start on the 1st.
     */
    public function summary(Request $request): JsonResponse
    {
        $request->validate(['compare' => ['nullable', 'in:previous,month_to_date']]);
        [$from, $toExclusive] = $this->rangeFromRequest($request);
        $affiliateId = $this->affiliateId($request);
        $summary = $this->reports->summary($from, $toExclusive, $affiliateId);

        if ($request->filled('compare')) {
            $summary['compare'] = $this->reports->summaryComparison(
                $summary, $from, $toExclusive, $affiliateId, $request->query('compare') === 'month_to_date',
            );
        }

        return response()->json($summary);
    }

    public function trend(Request $request): JsonResponse
    {
        [$from, $toExclusive] = $this->trendRangeFromRequest($request);

        return response()->json([
            'days' => $this->reports->dailyTrend($from, $toExclusive, $this->affiliateId($request)),
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

    /** ADR-086 PR-2 — Reseller-wallet breakdown, distinct from Affiliate above. */
    public function resellerBreakdown(Request $request): JsonResponse
    {
        [$from, $toExclusive] = $this->rangeFromRequest($request);

        return response()->json([
            'resellers' => $this->reports->resellerBreakdown($from, $toExclusive, $this->affiliateId($request)),
        ]);
    }

    /** ADR-104 R15/R16 — sales by channel, plus the reseller-wallet API vs Bot split. */
    public function channelBreakdown(Request $request): JsonResponse
    {
        [$from, $toExclusive] = $this->rangeFromRequest($request);

        return response()->json($this->reports->channelBreakdown($from, $toExclusive, $this->affiliateId($request)));
    }

    public function deliveryByGame(Request $request): JsonResponse
    {
        [$from, $toExclusive] = $this->rangeFromRequest($request);

        return response()->json([
            'games' => $this->reports->deliveryByGame($from, $toExclusive, $this->affiliateId($request)),
        ]);
    }

    /** ADR-104 R7 — company-wide only, so null under an affiliate filter (the page hides it). */
    public function accountingBridge(Request $request): JsonResponse
    {
        [$from, $toExclusive] = $this->rangeFromRequest($request);

        return response()->json([
            'bridge' => $this->affiliateId($request) === null ? $this->reports->accountingBridge($from, $toExclusive) : null,
        ]);
    }

    /** ADR-104 R8–R10 — failed orders and their compensation, plus store credit still owed (as of now). */
    public function failedCompensated(Request $request): JsonResponse
    {
        [$from, $toExclusive] = $this->rangeFromRequest($request);
        $affiliateId = $this->affiliateId($request);

        return response()->json([
            ...$this->reports->failedAndCompensated($from, $toExclusive, $affiliateId),
            'outstanding_store_credit' => $this->reports->outstandingStoreCredit($affiliateId),
            'outstanding_store_credit_as_of' => now()->toIso8601String(),
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
        $rangeLabel = $this->rangeLabel($from, $toExclusive);
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
        return $this->reports->dateRangeFromDates(
            $request->filled('from') ? $request->query('from') : null,
            $request->filled('to') ? $request->query('to') : null,
        );
    }

    /** ADR-104 R17 — the trend chart's range: "All time" runs from the first paid order. */
    private function trendRangeFromRequest(Request $request): array
    {
        return $this->reports->trendRange(...[...$this->rangeFromRequest($request), $this->affiliateId($request)]);
    }

    private function rangeLabel(?CarbonImmutable $from, ?CarbonImmutable $toExclusive): string
    {
        if ($from === null || $toExclusive === null) {
            return 'All time';
        }

        $fromKl = $from->setTimezone(ReportService::TIMEZONE)->toDateString();
        $toKl = $toExclusive->subDay()->setTimezone(ReportService::TIMEZONE)->toDateString();

        return $fromKl === $toKl ? $fromKl : "{$fromKl}_to_{$toKl}";
    }

    private function exportCsv(array $rows, string $rangeLabel): StreamedResponse
    {
        $filename = 'sales-report-'.str_replace(' ', '-', strtolower($rangeLabel)).'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Order #', 'Paid At', 'Customer', 'Affiliate', 'Game', 'Package', 'Payment Method',
                'Pricing Basis', 'Reseller', 'Delivery Status', 'Sales (RM)', 'Platform Profit (RM)', 'Affiliate Profit (RM)',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['order_number'],
                    $row['paid_at'],
                    $row['customer_email'],
                    $row['affiliate_name'] ?? '',
                    $row['game_name'] ?? '',
                    $row['package_name'] ?? '',
                    $row['payment_method'] ?? '',
                    $row['pricing_basis'],
                    $row['reseller_name'] ?? '',
                    $row['delivery_status'],
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

        // Landscape — the widened 13-column export (added 2026-09-11)
        // doesn't fit a portrait page legibly.
        return Pdf::loadView('reports.export-pdf', [
            'rows' => $rows,
            'rangeLabel' => $rangeLabel,
            'affiliateLabel' => $affiliateLabel,
        ])->setPaper('a4', 'landscape')->download($filename);
    }
}
