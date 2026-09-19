<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Accounting\MonthlyAccountingSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ADR-083 decision 8, fills ADR-110 PR-B — read-only Monthly Accounting
 * Summary. One period at a time; nothing is written here.
 */
class AccountingSummaryController extends Controller
{
    public function __construct(private readonly MonthlyAccountingSummaryService $summary) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', Rule::in(range(1, 12))],
        ]);

        return response()->json(
            $this->summary->forPeriod((int) $validated['year'], (int) $validated['month']),
        );
    }
}
