<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Models\SupplierRequestLog;
use App\Services\Report\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-051 (MUI-9) — read-only viewer over supplier_request_logs.
 * Payloads are already redacted at write time (SupplierRequestPayloadRedactor,
 * run before LogSupplierRequestJob ever queues), so show() returns the
 * stored row as-is — there is no further masking to do at read time.
 */
class RequestLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 25);

        $query = SupplierRequestLog::query()->with('supplier:id,name,slug')->latest('id');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->query('supplier_id'));
        }

        if ($request->filled('call_type')) {
            $query->where('call_type', $request->query('call_type'));
        }

        if ($request->filled('outcome')) {
            $query->where('outcome', $request->query('outcome'));
        }

        // Malaysia calendar days, the same bounds as the Orders filter.
        $dates = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        [$from, $toExclusive] = app(ReportService::class)->dateRangeFromDates($dates['from'] ?? null, $dates['to'] ?? null);
        $query->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($toExclusive, fn ($q) => $q->where('created_at', '<', $toExclusive));

        return response()->json($query->paginate($perPage)->withQueryString());
    }

    public function show(SupplierRequestLog $requestLog): JsonResponse
    {
        return response()->json($requestLog->load('supplier:id,name,slug'));
    }
}
