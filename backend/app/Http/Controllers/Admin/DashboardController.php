<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\Dashboard\DashboardService;
use App\Services\Supplier\SupplierBufferService;
use Illuminate\Http\JsonResponse;

/**
 * DASH-2 (ADR-045) system health plus the status strip's `attention`
 * (ADR-104 2026-10-15). The Dashboard's business figures are served by
 * ReportController.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * ADR-045 decision 22 — polled by the frontend every 60s, and
     * decision 11 — no caching, every call computes fresh.
     */
    public function health(): JsonResponse
    {
        return response()->json($this->dashboard->health());
    }

    /** ADR-115 2026-11-05 addendum, decision 10: /admin/balance's buffer cards. */
    public function supplierBuffers(SupplierBufferService $buffers): JsonResponse
    {
        $rows = Supplier::query()->where('is_active', true)->apiBacked()->orderBy('name')->get()
            ->map(fn (Supplier $s) => ($b = $buffers->assess($s)) === null ? null : [
                'supplier_id' => $s->id, 'name' => $s->name, 'currency' => $s->currency, ...$b->toArray(),
            ])
            ->filter()->values();

        return response()->json($rows);
    }
}
