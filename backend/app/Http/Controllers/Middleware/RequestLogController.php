<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Models\SupplierRequestLog;
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

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->query('from'));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->query('to'));
        }

        return response()->json($query->paginate($perPage)->withQueryString());
    }

    public function show(SupplierRequestLog $requestLog): JsonResponse
    {
        return response()->json($requestLog->load('supplier:id,name,slug'));
    }
}
