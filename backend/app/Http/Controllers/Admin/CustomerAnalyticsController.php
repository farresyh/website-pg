<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CustomerAnalyticsFilterRequest;
use App\Services\CustomerAnalytics\CustomerAnalyticsService;
use App\Support\MalaysiaTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ANL-1..5 (docs/prd.md §6.12). The rules live in CustomerAnalyticsService
 * (ADR-049, ADR-050 and their 2026-10-15 addenda); this controller only
 * hands it the validated filters.
 */
class CustomerAnalyticsController extends Controller
{
    public function __construct(private readonly CustomerAnalyticsService $analytics) {}

    public function summary(CustomerAnalyticsFilterRequest $request): JsonResponse
    {
        return response()->json($this->analytics->stats($request->filters()));
    }

    public function customers(CustomerAnalyticsFilterRequest $request): JsonResponse
    {
        return response()->json($this->analytics->customers(
            $request->filters(),
            $request->sort(),
            $request->integer('page', 1),
            $request->integer('per_page', CustomerAnalyticsService::PER_PAGE_OPTIONS[0]),
        ));
    }

    /**
     * ANL-5 (ADR-050) — always the customer's full lifetime across every
     * Affiliate and Reseller (decision 6); only the order history is paged.
     */
    public function show(Request $request, string $email): JsonResponse
    {
        $detail = $this->analytics->customerDetail($email, max(1, $request->integer('history_page', 1)));

        if ($detail === null) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        return response()->json($detail);
    }

    public function export(CustomerAnalyticsFilterRequest $request): StreamedResponse
    {
        $rows = $this->analytics->exportRows($request->filters(), $request->sort());
        $filename = 'customer-analytics-'.now()->setTimezone(MalaysiaTime::TIMEZONE)->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Email', 'Name', 'Source', 'Segment', 'Orders Count', 'Total Spent (RM)', 'Last Order']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['customer_email'],
                    $row['customer_name'] ?? '',
                    $row['source_label'],
                    $row['segment_label'],
                    $row['orders_count'],
                    number_format($row['total_spent'] / 100, 2, '.', ''),
                    $row['last_order_at'],
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
