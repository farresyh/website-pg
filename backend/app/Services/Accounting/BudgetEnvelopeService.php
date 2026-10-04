<?php

namespace App\Services\Accounting;

use App\Models\BudgetEnvelope;
use App\Models\BudgetEnvelopeEntry;
use App\Services\Report\ReportService;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * ADR-083 2026-09-28 "Envelope Ledger" addendum — the sole writer of
 * `budget_envelope_entries`. `recordEntry()` is the normal path;
 * `voidEntry()` is the only correction path (never an edit/delete —
 * `BudgetEnvelopeEntry` enforces this at the model layer);
 * `allocateMonthlyProfit()` is the once-a-month "allocate profit"
 * action, a thin wrapper writing one `MonthlyProfitAllocation` entry
 * per chosen envelope in a single transaction.
 */
final class BudgetEnvelopeService
{
    /**
     * Stores the optional receipt first, then the entry — one
     * transaction, so a failed insert never leaves an orphan receipt
     * file. `$amountSen` is signed by the caller (the controller/form
     * request validates it against the category's typical sign as a
     * soft warning, never a hard block — see
     * `BudgetEnvelopeEntryCategory::typicalSign()`).
     */
    /**
     * 2026-09-30 addendum: `$transactionDate` (the day money actually
     * moved, defaults to today when omitted — an entry recorded days
     * after the fact shouldn't silently claim it happened today),
     * `$paidFrom` (which real account funded it — `PaidFrom`, optional),
     * `$referenceNo` (optional free text — a bank reference, invoice
     * number, etc.).
     */
    public function recordEntry(
        BudgetEnvelope $envelope,
        BudgetEnvelopeEntryCategory $category,
        int $amountSen,
        string $description,
        ?UploadedFile $receipt,
        int $adminUserId,
        ?CarbonInterface $transactionDate = null,
        ?PaidFrom $paidFrom = null,
        ?string $referenceNo = null,
    ): BudgetEnvelopeEntry {
        return DB::transaction(function () use ($envelope, $category, $amountSen, $description, $receipt, $adminUserId, $transactionDate, $paidFrom, $referenceNo) {
            $receiptPath = $receipt !== null
                ? $receipt->store('accounting/budget-envelopes', config('filesystems.accounting_disk'))
                : null;

            return BudgetEnvelopeEntry::query()->create([
                'budget_envelope_id' => $envelope->id,
                'category' => $category->value,
                'amount_sen' => $amountSen,
                // A KL calendar date — `now()` alone is UTC and dated an
                // entry typed before 08:00 KL to the previous day.
                'transaction_date' => ($transactionDate ?? now(ReportService::TIMEZONE))->toDateString(),
                'description' => $description,
                'paid_from' => $paidFrom?->value,
                'reference_no' => $referenceNo,
                'receipt_path' => $receiptPath,
                'created_by' => $adminUserId,
            ]);
        });
    }

    /**
     * The only correction path — never edits/deletes the original
     * (model-enforced). Posts a new entry with the exact negated
     * amount, `reverses_entry_id` pointing back at the original, so
     * `BudgetEnvelope::balanceSen()`'s plain `SUM(amount_sen)` always
     * nets it out automatically. An already-voided entry (one that
     * already has a reversal pointing at it) cannot be voided again —
     * checked inside the lock to close a concurrent-double-void race.
     */
    public function voidEntry(BudgetEnvelopeEntry $entry, string $reason, int $adminUserId): BudgetEnvelopeEntry
    {
        return DB::transaction(function () use ($entry, $reason, $adminUserId) {
            $locked = BudgetEnvelopeEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            $alreadyVoided = BudgetEnvelopeEntry::query()->where('reverses_entry_id', $locked->id)->exists();
            if ($alreadyVoided) {
                throw ValidationException::withMessages([
                    'entry' => ['This entry has already been voided.'],
                ]);
            }

            return BudgetEnvelopeEntry::query()->create([
                'budget_envelope_id' => $locked->budget_envelope_id,
                'category' => BudgetEnvelopeEntryCategory::Adjustment->value,
                'amount_sen' => -$locked->amount_sen,
                // Dated with the entry it cancels, so any date filter nets
                // the pair to zero — never a reversal outside the period.
                'transaction_date' => ($locked->transaction_date ?? $locked->created_at->setTimezone(ReportService::TIMEZONE))->toDateString(),
                'description' => "Void: {$reason} (reversing entry #{$locked->id})",
                'reverses_entry_id' => $locked->id,
                'void_reason' => $reason,
                'created_by' => $adminUserId,
            ]);
        });
    }

    /**
     * "Allocate Monthly Profit" — the founder manually decides how
     * much of this month's net profit goes to which envelope (never an
     * automatic formula, a deliberate decision from the founder's own
     * grilling session). `$allocations` is `[budget_envelope_id =>
     * amount_sen]`, every value must be positive. One
     * `MonthlyProfitAllocation` entry per envelope, all in one
     * transaction.
     *
     * @param  array<int, int>  $allocations
     * @return list<BudgetEnvelopeEntry>
     */
    public function allocateMonthlyProfit(array $allocations, string $periodLabel, int $adminUserId): array
    {
        return DB::transaction(function () use ($allocations, $periodLabel, $adminUserId) {
            $entries = [];

            foreach ($allocations as $envelopeId => $amountSen) {
                $envelope = BudgetEnvelope::query()->findOrFail($envelopeId);

                $entries[] = $this->recordEntry(
                    $envelope,
                    BudgetEnvelopeEntryCategory::MonthlyProfitAllocation,
                    $amountSen,
                    "Monthly profit allocation — {$periodLabel}",
                    null,
                    $adminUserId,
                );
            }

            return $entries;
        });
    }

    public function downloadReceipt(BudgetEnvelopeEntry $entry)
    {
        if ($entry->receipt_path === null) {
            abort(404);
        }

        return Storage::disk(config('filesystems.accounting_disk'))->download(
            $entry->receipt_path,
            "budget-envelope-entry-{$entry->id}-receipt",
        );
    }
}
