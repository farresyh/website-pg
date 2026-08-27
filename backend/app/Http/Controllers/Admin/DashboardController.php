<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DASH-1..6 (docs/prd.md §6.2, ADR-045). See DashboardService's own
 * doc comments for the grilled/pinned definitions — this controller
 * only resolves request params, no calculation lives here, same split
 * ReportController already established.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    public function summary(): JsonResponse
    {
        return response()->json($this->dashboard->summary());
    }

    /**
     * ADR-045 decision 22 — the only one of these five endpoints
     * polled on a timer by the frontend (60s), and decision 11 — no
     * caching, every call computes fresh.
     */
    public function health(): JsonResponse
    {
        return response()->json($this->dashboard->health());
    }

    public function funnel(): JsonResponse
    {
        return response()->json($this->dashboard->funnel());
    }

    public function topGames(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 5);

        return response()->json($this->dashboard->topGames($limit));
    }

    /**
     * ADR-045 decision 19 — a single selected day, not a week
     * aggregate; `date` is required so the frontend's day selector
     * always drives this explicitly rather than an implicit default
     * silently changing as "today" rolls over.
     */
    public function hourlyActivity(Request $request): JsonResponse
    {
        $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json($this->dashboard->hourlyActivity($request->string('date')->toString()));
    }
}
