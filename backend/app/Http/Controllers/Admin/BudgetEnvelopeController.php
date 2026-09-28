<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AllocateMonthlyProfitRequest;
use App\Http\Requests\Admin\StoreBudgetEnvelopeEntryRequest;
use App\Http\Requests\Admin\StoreBudgetEnvelopeRequest;
use App\Http\Requests\Admin\VoidBudgetEnvelopeEntryRequest;
use App\Models\BudgetEnvelope;
use App\Models\BudgetEnvelopeEntry;
use App\Services\Accounting\BudgetEnvelopeEntryCategory;
use App\Services\Accounting\BudgetEnvelopeService;
use App\Services\Accounting\MonthlyAccountingSummaryService;
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

    /** Every envelope with its live balance, plus the current month's Monthly Summary lines as reference context for the "Allocate Monthly Profit" decision — deliberately not a single derived "net profit" figure (that formula was never grilled/pinned; the founder reads the same lines the existing Monthly Summary screen already shows and decides the split themselves). */
    public function index(): JsonResponse
    {
        $envelopes = BudgetEnvelope::query()->orderBy('id')->get()->map(fn (BudgetEnvelope $envelope) => [
            'id' => $envelope->id,
            'name' => $envelope->name,
            'balance_sen' => $envelope->balanceSen(),
        ]);

        $now = Carbon::now();

        return response()->json([
            'envelopes' => $envelopes,
            'categories' => collect(BudgetEnvelopeEntryCategory::cases())->map(fn (BudgetEnvelopeEntryCategory $c) => [
                'value' => $c->value,
                'label' => $c->label(),
                'typical_sign' => $c->typicalSign(),
            ]),
            'current_month_summary' => $this->summary->forPeriod($now->year, $now->month),
            'current_month_label' => $now->format('F Y'),
        ]);
    }

    public function store(StoreBudgetEnvelopeRequest $request): JsonResponse
    {
        $envelope = BudgetEnvelope::query()->create($request->validated());

        return response()->json(['envelope' => $envelope], 201);
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
        $from = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : null;
        $to = $request->filled('to') ? Carbon::parse($request->query('to'))->endOfDay() : null;

        $entries = $budgetEnvelope->entries()
            ->with('createdBy')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (BudgetEnvelopeEntry $entry) => [
                'id' => $entry->id,
                'category' => $entry->category->value,
                'category_label' => $entry->category->label(),
                'amount_sen' => $entry->amount_sen,
                'description' => $entry->description,
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
        $from = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : null;
        $to = $request->filled('to') ? Carbon::parse($request->query('to'))->endOfDay() : null;

        $entries = BudgetEnvelopeEntry::query()
            ->with(['budgetEnvelope', 'createdBy'])
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->orderBy('created_at')
            ->get();

        // Never date-scoped, unlike $entries above — a "Voided" tag must
        // stay correct even when the export's own date range excludes
        // the (possibly much later) void, same reasoning the Transaction
        // Register's own void-visibility fix already established.
        $reversedEntryIds = BudgetEnvelopeEntry::query()->whereNotNull('reverses_entry_id')->pluck('reverses_entry_id')->flip();

        return response()->streamDownload(function () use ($entries, $reversedEntryIds) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Envelope', 'Category', 'Amount (RM)', 'Description', 'Recorded By', 'Has Receipt', 'Status']);

            foreach ($entries as $entry) {
                $status = $entry->reverses_entry_id !== null
                    ? 'Void reversal'
                    : ($reversedEntryIds->has($entry->id) ? 'Voided' : 'Active');

                fputcsv($out, [
                    $entry->created_at->toIso8601String(),
                    $entry->budgetEnvelope->name,
                    $entry->category->label(),
                    number_format($entry->amount_sen / 100, 2, '.', ''),
                    $entry->description,
                    $entry->createdBy?->name ?? '',
                    $entry->receipt_path !== null ? 'Yes' : 'No',
                    $status,
                ]);
            }

            fclose($out);
        }, 'envelope-ledger.csv', ['Content-Type' => 'text/csv']);
    }
}
