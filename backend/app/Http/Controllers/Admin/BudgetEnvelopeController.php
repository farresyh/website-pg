<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AllocateMonthlyProfitRequest;
use App\Http\Requests\Admin\StoreBudgetEnvelopePostingRequest;
use App\Http\Requests\Admin\StoreBudgetEnvelopeRequest;
use App\Http\Requests\Admin\UpdateBudgetEnvelopeRequest;
use App\Http\Requests\Admin\VoidBudgetEnvelopePostingRequest;
use App\Models\BudgetEnvelope;
use App\Models\BudgetEnvelopeEntry;
use App\Models\BudgetEnvelopePosting;
use App\Services\Accounting\BudgetEnvelopeService;
use App\Services\Accounting\EnvelopePostingType;
use App\Services\Accounting\ExpenseCategory;
use App\Services\Accounting\FundType;
use App\Services\Accounting\MonthlyAccountingSummaryService;
use App\Services\Accounting\PaidFrom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BudgetEnvelopeController extends Controller
{
    public function __construct(
        private readonly BudgetEnvelopeService $envelopes,
        private readonly MonthlyAccountingSummaryService $summary,
    ) {}

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
        $options = fn (array $cases) => collect($cases)->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()])->values();

        return response()->json([
            'envelopes' => $envelopes,
            'posting_types' => $options(array_filter(EnvelopePostingType::cases(), fn (EnvelopePostingType $t) => $t->isManual())),
            'directors' => $options(PaidFrom::directors()),
            'fund_types' => $options(FundType::cases()),
            'expense_categories' => $options(ExpenseCategory::cases()),
            'loan_balances' => collect($this->envelopes->loanBalances())
                ->map(fn (int $balance, string $director) => ['counterparty' => $director, 'label' => PaidFrom::from($director)->label(), 'balance_sen' => $balance])
                ->values(),
            // Until the month close replaces it (ADR-083 2026-10-10 addendum,
            // decision 9, PR-2): reference figures and a rough, unaudited
            // estimate behind the manual "Allocate Monthly Profit" form.
            'current_month_summary' => $monthSummary,
            'current_month_rough_pl_estimate_sen' => $monthSummary['sales_revenue_sen']
                + $monthSummary['membership_revenue_sen']
                - $monthSummary['cogs_sen']
                + $monthSummary['payment_processing_gain_loss_sen']
                - $monthSummary['bank_transfer_fees_sen']
                - $monthSummary['affiliate_commission_expense_sen']
                // ADR-083 2026-10-08 addendum: profit either way, revenue or contra-commission.
                + $monthSummary['affiliate_tier_fees_sen']
                - $monthSummary['voucher_liability_issued_sen'],
            'current_month_label' => $now->format('F Y'),
        ]);
    }

    public function store(StoreBudgetEnvelopeRequest $request): JsonResponse
    {
        $envelope = BudgetEnvelope::query()->create($request->validated());

        return response()->json(['envelope' => $envelope], 201);
    }

    public function update(UpdateBudgetEnvelopeRequest $request, BudgetEnvelope $budgetEnvelope): JsonResponse
    {
        // Decision 6: archiving an envelope that still holds money would hide that money from the grid.
        if ($request->validated('is_active') === false && $budgetEnvelope->balanceSen() !== 0) {
            throw ValidationException::withMessages([
                'is_active' => ['Move this envelope\'s balance out with a transfer before archiving it.'],
            ]);
        }

        $budgetEnvelope->update($request->validated());

        Log::info('Budget envelope updated', [
            'budget_envelope_id' => $budgetEnvelope->id,
            'changes' => $request->validated(),
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json(['envelope' => $budgetEnvelope->fresh()]);
    }

    public function storePosting(StoreBudgetEnvelopePostingRequest $request): JsonResponse
    {
        $data = $request->validated();

        $posting = $this->envelopes->post(
            type: EnvelopePostingType::from($data['type']),
            lines: $data['lines'],
            transactionDate: $data['transaction_date'],
            description: $data['description'],
            adminUserId: $request->user()->id,
            counterparty: isset($data['counterparty']) ? PaidFrom::from($data['counterparty']) : null,
            fundType: isset($data['fund_type']) ? FundType::from($data['fund_type']) : null,
            expenseCategory: isset($data['expense_category']) ? ExpenseCategory::from($data['expense_category']) : null,
            referenceNo: $data['reference_no'] ?? null,
            receipt: $request->file('receipt'),
        );

        Log::info('Envelope posting recorded', [
            'posting_id' => $posting->id,
            'type' => $posting->type->value,
            'amount_sen' => $posting->amount_sen,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json(['posting' => $posting], 201);
    }

    public function voidPosting(VoidBudgetEnvelopePostingRequest $request, BudgetEnvelopePosting $posting): JsonResponse
    {
        $reversal = $this->envelopes->void($posting, $request->validated('reason'), $request->user()->id);

        Log::warning('Envelope posting voided', [
            'posting_id' => $posting->id,
            'reversal_posting_id' => $reversal->id,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json(['reversal' => $reversal], 201);
    }

    /** One row per line in this envelope, carrying its posting's details. */
    public function entries(Request $request, BudgetEnvelope $budgetEnvelope): JsonResponse
    {
        // Filtered on the posting's `transaction_date` — when the money
        // moved, a KL calendar date (item 63). A void is dated with the
        // posting it cancels, so a filtered period nets the pair to zero.
        $from = self::klDate($request->query('from'));
        $to = self::klDate($request->query('to'));

        $entries = $budgetEnvelope->entries()
            ->whereHas('posting', fn ($q) => $q
                ->when($from, fn ($q) => $q->whereDate('transaction_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('transaction_date', '<=', $to)))
            ->with(['posting.createdBy', 'posting.reversal:id,reverses_posting_id'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (BudgetEnvelopeEntry $entry) => $this->entryRow($entry));

        return response()->json(['entries' => $entries]);
    }

    public function allocateMonthlyProfit(AllocateMonthlyProfitRequest $request): JsonResponse
    {
        $data = $request->validated();
        $allocations = collect($data['allocations'])->pluck('amount_sen', 'budget_envelope_id')->all();

        $posting = $this->envelopes->allocateMonthlyProfit($allocations, $data['period_label'], $request->user()->id);

        Log::info('Monthly profit allocated across envelopes', [
            'period_label' => $data['period_label'],
            'posting_id' => $posting->id,
            'allocations' => $allocations,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json(['posting' => $posting], 201);
    }

    public function downloadReceipt(BudgetEnvelopePosting $posting): StreamedResponse
    {
        return $this->envelopes->downloadReceipt($posting);
    }

    public function export(Request $request): StreamedResponse
    {
        $from = self::klDate($request->query('from'));
        $to = self::klDate($request->query('to'));

        $entries = BudgetEnvelopeEntry::query()
            ->whereHas('posting', fn ($q) => $q
                ->when($from, fn ($q) => $q->whereDate('transaction_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('transaction_date', '<=', $to)))
            // The reversal is loaded without a date scope, so "Voided" stays
            // right even when the void falls outside the exported range.
            ->with(['budgetEnvelope', 'posting.createdBy', 'posting.reversal:id,reverses_posting_id'])
            ->orderBy('budget_envelope_posting_id')
            ->orderBy('id')
            ->get();

        return response()->streamDownload(function () use ($entries) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Posting', 'Transaction Date', 'Recorded At', 'Type', 'Envelope', 'Amount (RM)', 'Description', 'Counterparty', 'Fund Type', 'Expense Category', 'Reference', 'Recorded By', 'Has Receipt', 'Status']);

            foreach ($entries as $entry) {
                $posting = $entry->posting;
                fputcsv($out, [
                    $posting->id,
                    $posting->transaction_date->toDateString(),
                    $posting->created_at->toIso8601String(),
                    $posting->type->label(),
                    $entry->budgetEnvelope->name,
                    number_format($entry->amount_sen / 100, 2, '.', ''),
                    $posting->description,
                    $posting->counterparty?->label() ?? '',
                    $posting->fund_type?->label() ?? '',
                    $posting->expense_category?->label() ?? '',
                    $posting->reference_no ?? '',
                    $posting->createdBy?->name ?? '',
                    $posting->receipt_path !== null ? 'Yes' : 'No',
                    self::status($posting),
                ]);
            }

            fclose($out);
        }, 'envelope-ledger.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array<string, mixed> */
    private function entryRow(BudgetEnvelopeEntry $entry): array
    {
        $posting = $entry->posting;

        return [
            'id' => $entry->id,
            'posting_id' => $posting->id,
            'type' => $posting->type->value,
            'type_label' => $posting->type->label(),
            'amount_sen' => $entry->amount_sen,
            'posting_amount_sen' => $posting->amount_sen,
            'transaction_date' => $posting->transaction_date->toDateString(),
            'description' => $posting->description,
            'counterparty' => $posting->counterparty?->value,
            'counterparty_label' => $posting->counterparty?->label(),
            'fund_type_label' => $posting->fund_type?->label(),
            'expense_category_label' => $posting->expense_category?->label(),
            'reference_no' => $posting->reference_no,
            'has_receipt' => $posting->receipt_path !== null,
            'reverses_posting_id' => $posting->reverses_posting_id,
            'is_voided' => $posting->reversal !== null,
            'created_by' => $posting->createdBy?->name,
            'created_at' => $posting->created_at->toIso8601String(),
        ];
    }

    private static function status(BudgetEnvelopePosting $posting): string
    {
        return match (true) {
            $posting->reverses_posting_id !== null => 'Void reversal',
            $posting->reversal !== null => 'Voided',
            default => 'Active',
        };
    }

    private static function klDate(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
