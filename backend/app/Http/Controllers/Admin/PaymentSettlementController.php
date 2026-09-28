<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePaymentSettlementRequest;
use App\Http\Requests\Admin\UploadPaymentSettlementRequest;
use App\Models\ChipSettledTransaction;
use App\Models\PaymentSettlement;
use App\Services\Accounting\SettlementReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-110 PR-B, fills ADR-083 decision 7 — "CHIP Settlements" screen.
 * Deliberately under `/admin/accounting` (not `/middleware`), same
 * reasoning as `SupplierTransferController`'s own doc comment: this is
 * a bookkeeping/reconciliation action, not a supplier/payment
 * *integration* one.
 */
class PaymentSettlementController extends Controller
{
    public function __construct(private readonly SettlementReconciliationService $reconciliation) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 25);

        return response()->json(
            PaymentSettlement::query()->orderByDesc('date_from')->paginate($perPage)->withQueryString(),
        );
    }

    /**
     * `paid_but_not_settled` is recomputed live on every view (never
     * stored) — see `SettlementReconciliationService::paidButNotSettled()`'s
     * own doc comment for why a snapshot frozen at upload time would go
     * stale.
     */
    public function show(Request $request, PaymentSettlement $settlement): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 25);

        return response()->json([
            'settlement' => $settlement,
            'transactions' => $settlement->transactions()->orderByDesc('settled_on')->paginate($perPage)->withQueryString(),
            'paid_but_not_settled' => $this->reconciliation->paidButNotSettled($settlement->date_from, $settlement->date_to),
        ]);
    }

    /**
     * ADR-083 2026-09-28 addendum — cross-window date-range view: matched
     * CHIP transactions across every upload window that falls inside
     * `settled_on` between `from`/`to`, not one window's own drill-down.
     * No new table — `chip_settled_transactions` is already indexed on
     * `settled_on` and dedup-safe by its own unique `transaction_id`
     * (ADR-110), so a cross-window read here carries no double-count
     * risk. Each row also carries its parent settlement's window
     * (`settlement_id`/`date_from`/`date_to`) so the frontend can link
     * back to "View Window".
     */
    public function transactions(Request $request): JsonResponse
    {
        $from = $request->filled('from') ? CarbonImmutable::parse($request->query('from'))->startOfDay() : null;
        $to = $request->filled('to') ? CarbonImmutable::parse($request->query('to'))->endOfDay() : null;
        $perPage = (int) $request->query('per_page', 20);

        return response()->json(
            ChipSettledTransaction::query()
                ->with('paymentSettlement')
                ->when($from, fn ($q) => $q->where('settled_on', '>=', $from))
                ->when($to, fn ($q) => $q->where('settled_on', '<=', $to))
                ->orderByDesc('settled_on')
                ->paginate($perPage)
                ->withQueryString(),
        );
    }

    public function store(UploadPaymentSettlementRequest $request): JsonResponse
    {
        $file = $request->file('file');

        $result = $this->reconciliation->ingest(
            $file->getRealPath(),
            $file->getClientOriginalName(),
            $request->user()->id,
        );

        return response()->json([
            'settlement' => $result->settlement,
            'newly_matched_count' => $result->newlyMatchedCount,
            'newly_unmatched_count' => $result->newlyUnmatchedCount,
            'already_reconciled_skipped_count' => $result->alreadyReconciledSkippedCount,
            'unmatched_transaction_ids' => $result->unmatchedTransactionIds,
            'paid_but_not_settled' => $result->paidButNotSettled,
        ], 201);
    }

    /**
     * ADR-110 PR-B addendum (automatic reconciliation) — only
     * `actual_bank_amount_sen` (a purely optional founder annotation)
     * and `variance_note` (a free-text note) are writable here.
     * `status` is fully computed at ingest and never touched by this
     * endpoint.
     */
    public function update(UpdatePaymentSettlementRequest $request, PaymentSettlement $settlement): JsonResponse
    {
        $settlement->update($request->validated());

        return response()->json($settlement->fresh());
    }
}
