<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CustomerAnalytics\CustomerAnalyticsService;
use App\Services\CustomerAnalytics\CustomerSegment;
use App\Services\Report\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ANL-1..4 (docs/prd.md §6.12). See CustomerAnalyticsService's own doc
 * comment + ADR-049 for the identity/segmentation rules grilled and
 * pinned 2026-08-28 — this controller only resolves request params
 * into the service's params, no business logic lives here.
 */
class CustomerAnalyticsController extends Controller
{
    public function __construct(
        private readonly CustomerAnalyticsService $analytics,
        private readonly ReportService $reports,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        [$from, $toExclusive] = $this->rangeFromRequest($request);

        return response()->json(
            $this->analytics->stats($from, $toExclusive, $this->resellerId($request)),
        );
    }

    public function customers(Request $request): JsonResponse
    {
        [$from, $toExclusive] = $this->rangeFromRequest($request);

        return response()->json([
            'customers' => $this->analytics->customers(
                $from,
                $toExclusive,
                $this->resellerId($request),
                $this->segmentFromRequest($request),
            ),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $toExclusive] = $this->rangeFromRequest($request);

        $rows = $this->analytics->customers(
            $from,
            $toExclusive,
            $this->resellerId($request),
            $this->segmentFromRequest($request),
        );

        $filename = 'customer-analytics-'.now()->setTimezone(CustomerAnalyticsService::TIMEZONE)->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Email', 'Name', 'Segment', 'Orders Count', 'Total Spent (RM)', 'Last Order']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['customer_email'],
                    $row['customer_name'] ?? '',
                    $row['segment_label'],
                    $row['orders_count'],
                    number_format($row['total_spent'] / 100, 2, '.', ''),
                    $row['last_order_at'],
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function resellerId(Request $request): ?int
    {
        return $request->filled('reseller_id') ? (int) $request->query('reseller_id') : null;
    }

    private function segmentFromRequest(Request $request): ?CustomerSegment
    {
        $value = $request->query('segment');

        return $value !== null ? CustomerSegment::tryFrom($value) : null;
    }

    private function rangeFromRequest(Request $request): array
    {
        return $this->reports->dateRangeForYearMonth(
            $request->integer('year') ?: null,
            $request->integer('month') ?: null,
        );
    }
}
