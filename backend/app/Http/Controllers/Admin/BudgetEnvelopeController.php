<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AllocateMonthlyProfitRequest;
use App\Http\Requests\Admin\StoreBudgetEnvelopeEntryRequest;
use App\Http\Requests\Admin\StoreBudgetEnvelopeRequest;
use App\Http\Requests\Admin\UpdateBudgetEnvelopeRequest;
use App\Http\Requests\Admin\VoidBudgetEnvelopeEntryRequest;
use App\Models\BudgetEnvelope;
use App\Models\BudgetEnvelopeEntry;
use App\Services\Accounting\BudgetEnvelopeEntryCategory;
use App\Services\Accounting\BudgetEnvelopeService;
use App\Services\Accounting\MonthlyAccountingSummaryService;
use App\Services\Accounting\PaidFrom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-083 2026-09-28 "Envelope Ledger" addendum — the discretionary,
 * director-controlled budget tracking (Capital Rolling, Marketing
 * Budget, etc.) the founder asked for after re-challenging (grilling)
 * the original ADR-083 decision 11 ("the platform does not model
 * equity, capital or drawings"). Deliberately NOT a real double-entry
 * engine — a categorized, append-only record with running balances,
 * per the founder's own explicit scope decision. `super_admin` only,
 * same tier as the rest of the `/accounting` family.
 */
class BudgetEnvelopeController extends Controller
{
    public function __construct(
        private readonly BudgetEnvelopeService $envelopes,
        private readonly MonthlyAccountingSummaryService $summary,
    ) {}

    /** Every envelope (active and archived — an archived one's history must stay visible/exportable) with its live balance, plus the current month's Monthly Summary lines as reference context for the "Allocate Monthly Profit" decision — deliberately not a single derived "net profit" figure (that formula was never grilled/pinned; the founder reads the same lines the existing Monthly Summary screen already shows and decides the split themselves). */
    public function index(): JsonResponse
    {
        $envelopes = BudgetEnvelope::query()->orderBy('id')->get()->map(fn (BudgetEnvelope $envelope) => [
            'id' => $envelope->id,
            'name' => $envelope->name,
            'is_active' => $envelope->is_active,
            'balance_sen' => $envelope->balanceSen(),
        ]);

        $now = Carbon::now();
        $monthSummary = $this->summary->forPeriod($now->year, $now->month);

        return response()->json([
            'envelopes' => $envelopes,
            // Adjustment excluded — it's the void mechanism's own internal
            // tag (BudgetEnvelopeService::voidEntry() sets it automatically
            // on a reversal entry), never something an admin should pick
            // manually here. A loose correction with no specific
            // originating entry to void still fits under "OPEX — Other".
            'categories' => collect(BudgetEnvelopeEntryCategory::cases())
                ->reject(fn (BudgetEnvelopeEntryCategory $c) => $c === BudgetEnvelopeEntryCategory::Adjustment)
                ->map(fn (BudgetEnvelopeEntryCategory $c) => [
                    'value' => $c->value,
                    'label' => $c->label(),
                    'typical_sign' => $c->typicalSign(),
                ])
                ->values(),
            'paid_from_options' => collect(PaidFrom::cases())
                ->map(fn (PaidFrom $p) => ['value' => $p->value, 'label' => $p->label()])
                ->values(),
            'current_month_summary' => $monthSummary,
            // Deliberately labeled "rough"/"unaudited" — a soft warning
            // aid for Allocate Monthly Profit, never the authoritative
            // "net profit" figure the founder's own grilling session
            // decided against computing. Sums the P&L-shaped lines only
            // (never supplier_prepaid_topup/fx_variance/reseller_wallet_
            // balance — those are capital movements or a liability
            // snapshot, not P&L). `bank_transfer_fees_sen` (2026-09-30
            // addendum, split out of supplier_prepaid_topup_sen in the
            // 2026-09-30 external-review fix) is a real expense —
            // missing from this estimate would have been the exact
            // "invisible cost" gap this rough figure exists to surface.
            'current_month_rough_pl_estimate_sen' => $monthSummary['sales_revenue_sen']
                + $monthSummary['membership_revenue_sen']
                - $monthSummary['cogs_sen']
                + $monthSummary['payment_processing_gain_loss_sen']
                - $monthSummary['bank_transfer_fees_sen']
                - $monthSummary['affiliate_commission_expense_sen']
                - $monthSummary['voucher_liability_issued_sen'],
            'current_month_label' => $now->format('F Y'),
        ]);
    }

    public function store(StoreBudgetEnvelopeRequest $request): JsonResponse
    {
        $envelope = BudgetEnvelope::query()->create($request->validated());

        return response()->json(['envelope' => $envelope], 201);
    }

    /**
     * Rename and/or archive/reactivate — found missing the day after
     * launch (founder question). Never a hard delete: an envelope with
     * any recorded entry is protected by `restrictOnDelete()` at the DB
     * layer regardless, so "delete" was never a safe verb to offer here
     * in the first place — archiving hides a mistaken/retired envelope
     * from the active list while its full entry history stays visible/
     * exportable forever.
     */
    public function update(UpdateBudgetEnvelopeRequest $request, BudgetEnvelope $budgetEnvelope): JsonResponse
    {
        $budgetEnvelope->update($request->validated());

        Log::info('Budget envelope updated', [
            'budget_envelope_id' => $budgetEnvelope->id,
            'changes' => $request->validated(),
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json(['envelope' => $budgetEnvelope->fresh()]);
    }

    /**
     * `amount_sen` in the request is always a positive magnitude
     * (beginner-friendly — see the FormRequest's own doc comment); the
     * signed value written to the ledger comes from the category's
     * `typicalSign()`, except `Adjustment`, which reads the explicit
     * `direction` field instead.
     */
    public function storeEntry(StoreBudgetEnvelopeEntryRequest $request, BudgetEnvelope $budgetEnvelope): JsonResponse
    {
        $data = $request->validated();
        $category = BudgetEnvelopeEntryCategory::from($data['category']);
        $magnitude = (int) $data['amount_sen'];

        $signedAmount = match ($category->typicalSign()) {
            'positive' => $magnitude,
            'negative' => -$magnitude,
            'either' => $data['direction'] === 'out' ? -$magnitude : $magnitude,
        };

        $entry = $this->envelopes->recordEntry(
            $budgetEnvelope,
            $category,
            $signedAmount,
            $data['description'],
            $request->file('receipt'),
            $request->user()->id,
            isset($data['transaction_date']) ? Carbon::parse($data['transaction_date']) : null,
            isset($data['paid_from']) ? PaidFrom::from($data['paid_from']) : null,
            $data['reference_no'] ?? null,
        );

        Log::info('Budget envelope entry recorded', [
            'budget_envelope_id' => $budgetEnvelope->id,
            'entry_id' => $entry->id,
            'category' => $category->value,
            'amount_sen' => $signedAmount,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'entry' => $entry,
            'balance_sen' => $budgetEnvelope->balanceSen(),
        ], 201);
    }

    /** @return list<array<string, mixed>> */
    public function entries(Request $request, BudgetEnvelope $budgetEnvelope): JsonResponse
    {
        // Filtered on `transaction_date` — when the money moved, a KL
        // calendar date — not when the row was typed in (item 63,
        // founder's call 2026-10-04). Every writer sets it.
        $from = self::klDate($request->query('from'));
        $to = self::klDate($request->query('to'));

        $entries = $budgetEnvelope->entries()
            ->with('createdBy')
            ->when($from, fn ($q) => $q->whereDate('transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('transaction_date', '<=', $to))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (BudgetEnvelopeEntry $entry) => [
                'id' => $entry->id,
                'category' => $entry->category->value,
                'category_label' => $entry->category->label(),
                'amount_sen' => $entry->amount_sen,
                'transaction_date' => $entry->transaction_date?->toDateString(),
                'description' => $entry->description,
                'paid_from' => $entry->paid_from?->value,
                'paid_from_label' => $entry->paid_from?->label(),
                'reference_no' => $entry->reference_no,
                'has_receipt' => $entry->receipt_path !== null,
                'reverses_entry_id' => $entry->reverses_entry_id,
                'is_voided' => BudgetEnvelopeEntry::query()->where('reverses_entry_id', $entry->id)->exists(),
                'created_by' => $entry->createdBy?->name,
                'created_at' => $entry->created_at->toIso8601String(),
            ]);

        return response()->json(['entries' => $entries]);
    }

    public function voidEntry(VoidBudgetEnvelopeEntryRequest $request, BudgetEnvelopeEntry $entry): JsonResponse
    {
        $reversal = $this->envelopes->voidEntry($entry, $request->validated('reason'), $request->user()->id);

        Log::warning('Budget envelope entry voided', [
            'entry_id' => $entry->id,
            'reversal_entry_id' => $reversal->id,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'reversal' => $reversal,
            'balance_sen' => $entry->budgetEnvelope->balanceSen(),
        ], 201);
    }

    public function allocateMonthlyProfit(AllocateMonthlyProfitRequest $request): JsonResponse
    {
        $data = $request->validated();
        $allocations = collect($data['allocations'])->pluck('amount_sen', 'budget_envelope_id')->all();

        $entries = $this->envelopes->allocateMonthlyProfit($allocations, $data['period_label'], $request->user()->id);

        Log::info('Monthly profit allocated across envelopes', [
            'period_label' => $data['period_label'],
            'allocations' => $allocations,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json(['entries' => $entries], 201);
    }

    public function downloadReceipt(BudgetEnvelopeEntry $entry): StreamedResponse
    {
        return $this->envelopes->downloadReceipt($entry);
    }

    /**
     * One CSV across every envelope's entries — deliberately a
     * separate download from the Transaction Register's own export
     * (agreed with the founder during grilling): operational money
     * (orders/supplier/vouchers/withdrawals) and discretionary money
     * (capital/OPEX/marketing/dividends) stay in two files rather than
     * one merged one, easier for an auditor to reason about.
     */
    public function export(Request $request): StreamedResponse
    {
        // Filtered on `transaction_date` — when the money moved, a KL
        // calendar date — not when the row was typed in (item 63,
        // founder's call 2026-10-04). Every writer sets it.
        $from = self::klDate($request->query('from'));
        $to = self::klDate($request->query('to'));

        $entries = BudgetEnvelopeEntry::query()
            ->with(['budgetEnvelope', 'createdBy'])
            ->when($from, fn ($q) => $q->whereDate('transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('transaction_date', '<=', $to))
            ->orderBy('created_at')
            ->get();

        // Never date-scoped, unlike $entries above — a "Voided" tag must
        // stay correct even when the export's own date range excludes
        // the (possibly much later) void, same reasoning the Transaction
        // Register's own void-visibility fix already established.
        $reversedEntryIds = BudgetEnvelopeEntry::query()->whereNotNull('reverses_entry_id')->pluck('reverses_entry_id')->flip();

        return response()->streamDownload(function () use ($entries, $reversedEntryIds) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Recorded At', 'Transaction Date', 'Envelope', 'Category', 'Amount (RM)', 'Description', 'Paid From', 'Reference', 'Recorded By', 'Has Receipt', 'Status']);

            foreach ($entries as $entry) {
                $status = $entry->reverses_entry_id !== null
                    ? 'Void reversal'
                    : ($reversedEntryIds->has($entry->id) ? 'Voided' : 'Active');

                fputcsv($out, [
                    $entry->created_at->toIso8601String(),
                    $entry->transaction_date?->toDateString() ?? '',
                    $entry->budgetEnvelope->name,
                    $entry->category->label(),
                    number_format($entry->amount_sen / 100, 2, '.', ''),
                    $entry->description,
                    $entry->paid_from?->label() ?? '',
                    $entry->reference_no ?? '',
                    $entry->createdBy?->name ?? '',
                    $entry->receipt_path !== null ? 'Yes' : 'No',
                    $status,
                ]);
            }

            fclose($out);
        }, 'envelope-ledger.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Item 63 (2026-10-04): `from`/`to` are KL calendar dates — the same
     * day bounds Reports and Orders use (`ReportService::dateRangeFromDates()`,
     * exclusive upper bound). They used to be parsed as UTC days, cutting
     * at 08:00 KL. A malformed value means "no bound".
     */
    private static function klDate(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
