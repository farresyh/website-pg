<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Services\Reseller\ResellerEarningsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-059 decision 2: the reseller portal Dashboard — earnings balance,
 * today's / this-month's paid sales, current wholesale-tier status.
 * Runs under the `reseller` guard + `reseller.context` (ADR-058), so
 * every tenant-scoped read is already constrained; the earnings read
 * goes through ResellerEarningsService (decision 5).
 */
class DashboardController extends Controller
{
    public function __construct(private readonly ResellerEarningsService $earnings) {}

    public function show(Request $request): JsonResponse
    {
        $reseller = $request->user()->reseller;

        return response()->json($this->earnings->dashboardStats($reseller));
    }
}
