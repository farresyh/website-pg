<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportClientErrorRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * ADR-044 decision 8 — a narrow log sink for one specific event type
 * (a zod response-schema mismatch caught in admin/ or storefront/),
 * not a general error-monitoring/observability platform. Purpose:
 * give the founder backend-log visibility into frontend/backend
 * contract drift that would otherwise only ever appear in a
 * customer's own browser console, invisible to anyone.
 */
class ClientErrorController extends Controller
{
    public function store(ReportClientErrorRequest $request): JsonResponse
    {
        Log::warning('Frontend schema drift reported', [
            'schema' => $request->string('schema')->toString(),
            'path' => $request->string('path')->toString(),
            'error' => $request->string('error')->toString(),
        ]);

        return response()->json([], 204);
    }
}
