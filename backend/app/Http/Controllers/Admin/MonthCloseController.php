<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CloseAccountingMonthRequest;
use App\Http\Requests\Admin\SaveCashAccountRequest;
use App\Http\Requests\Admin\VoidBudgetEnvelopePostingRequest;
use App\Models\AccountingPeriodClose;
use App\Models\CashAccount;
use App\Services\Accounting\MonthCloseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/** ADR-083 2026-10-10 addendum, decisions 9–14 — the month close on the Monthly Summary page. */
class MonthCloseController extends Controller
{
    public function __construct(private readonly MonthCloseService $closes) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', Rule::in(range(1, 12))],
        ]);

        return response()->json($this->closes->preview((int) $validated['year'], (int) $validated['month']));
    }

    public function store(CloseAccountingMonthRequest $request): JsonResponse
    {
        $data = $request->validated();

        $close = $this->closes->close(
            (int) $data['year'],
            (int) $data['month'],
            $data['lines'],
            $data['cash_balances'],
            $data['gap_note'] ?? null,
            $request->user()->id,
        );

        Log::info('Accounting month closed', [
            'close_id' => $close->id,
            'period_month' => $close->period_month->toDateString(),
            'allocated_sen' => $close->allocated_sen,
            'gap_sen' => $close->gap_sen,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json(['close' => $close], 201);
    }

    /** Same shape as voiding a posting: a required reason. */
    public function reopen(VoidBudgetEnvelopePostingRequest $request, AccountingPeriodClose $close): JsonResponse
    {
        $close = $this->closes->reopen($close, $request->validated('reason'), $request->user()->id);

        Log::warning('Accounting month close reopened', [
            'close_id' => $close->id,
            'period_month' => $close->period_month->toDateString(),
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json(['close' => $close]);
    }

    public function storeCashAccount(SaveCashAccountRequest $request): JsonResponse
    {
        return response()->json(['cash_account' => CashAccount::query()->create($request->validated())], 201);
    }

    public function updateCashAccount(SaveCashAccountRequest $request, CashAccount $cashAccount): JsonResponse
    {
        $cashAccount->update($request->validated());

        return response()->json(['cash_account' => $cashAccount->fresh()]);
    }
}
