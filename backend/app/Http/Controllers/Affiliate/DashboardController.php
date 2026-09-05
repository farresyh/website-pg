<?php

namespace App\Http\Controllers\Affiliate;

use App\Http\Controllers\Controller;
use App\Services\Affiliate\AffiliateEarningsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-059 decision 2: the affiliate portal Dashboard — earnings balance,
 * today's / this-month's paid sales, current wholesale-tier status.
 * Runs under the `affiliate` guard + `affiliate.context` (ADR-058), so
 * every tenant-scoped read is already constrained; the earnings read
 * goes through AffiliateEarningsService (decision 5).
 */
class DashboardController extends Controller
{
    public function __construct(private readonly AffiliateEarningsService $earnings) {}

    public function show(Request $request): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();

        return response()->json($this->earnings->dashboardStats($affiliate));
    }
}
