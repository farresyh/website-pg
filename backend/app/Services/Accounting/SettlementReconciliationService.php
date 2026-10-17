<?php

namespace App\Services\Accounting;

use App\Models\ChipSettledTransaction;
use App\Models\MembershipCheckoutAttempt;
use App\Models\Order;
use App\Models\PaymentSettlement;
use App\Models\WalletTopupAttempt;
use App\Services\Membership\MembershipCheckoutAttemptStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Report\ReportService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use App\Support\MalaysiaTime;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Exception\OpenSpoutException;
use RuntimeException;

/**
 * ADR-110 PR-B, fills ADR-083 decision 7 (revised by this ADR's own
 * same-day "automatic reconciliation" addendum — see `docs/adr.md`):
 * ingests one CHIP settlement `.xlsx`, matches every transaction row to
 * whichever of the three CHIP-charging flows this platform has
 * (retail/affiliate order, self-serve membership subscription,
 * self-serve reseller wallet top-up), and computes `status`
 * automatically from a per-transaction GROSS comparison between this
 * platform's own records and CHIP's file — never a founder-typed bank
 * figure. `matched_*` sums ONLY the transactions this specific
 * settlement actually matched, never a calendar-window query (the
 * original bug this addendum fixes — comparing "everything paid in the
 * window" against "only what's actually settled" always looked wrong
 * whenever anything near the window's tail end hadn't settled yet).
 */
final class SettlementReconciliationService
{
    public function __construct(private readonly SettlementFileParser $parser) {}

    /**
     * §16 item 120 (ADR-083 2026-10-18 addendum): the original `.xlsx` is
     * kept on the accounting disk under `accounting/chip-settlements/YYYY/MM/`
     * (by `date_to`, the payout's bank month) with its SHA-256, and only
     * when the whole ingest commits. A file with no transaction not
     * already reconciled (the same file again, or a re-export of a
     * period already uploaded) is refused instead of leaving an empty
     * settlement row; a partly overlapping file still goes in.
     */
    public function ingest(UploadedFile $file, ?int $adminUserId): SettlementIngestResult
    {
        $parsed = $this->parse($file);
        $transactionIds = array_map(fn (ParsedSettlementTransaction $t) => $t->transactionId, $parsed->transactions);

        if ($transactionIds === []) {
            throw ValidationException::withMessages(['file' => ['This settlement file has no transactions.']]);
        }

        $alreadyReconciled = ChipSettledTransaction::query()
            ->whereIn('transaction_id', $transactionIds)
            ->pluck('transaction_id')
            ->all();

        if (count(array_unique($alreadyReconciled)) === count(array_unique($transactionIds))) {
            $this->refuseNothingNew($transactionIds);
        }

        $newTransactions = array_filter(
            $parsed->transactions,
            fn (ParsedSettlementTransaction $t) => ! in_array($t->transactionId, $alreadyReconciled, true),
        );

        try {
            $outcome = AccountingFileStore::storeThen(
                $file,
                config('filesystems.accounting_disk'),
                'accounting/chip-settlements',
                $parsed->dateTo->toDateString(),
                fn (?string $path) => DB::transaction(fn () => $this->record($parsed, $newTransactions, $file, $path, $adminUserId, count($alreadyReconciled))),
            );
        } catch (UniqueConstraintViolationException) {
            // The same file uploaded twice at once: the second loses the file_sha256 race.
            $this->refuseNothingNew($transactionIds);
        }

        return new SettlementIngestResult(
            settlement: $outcome['settlement']->fresh(),
            newlyMatchedCount: $outcome['newly_matched'],
            newlyUnmatchedCount: $outcome['newly_unmatched'],
            alreadyReconciledSkippedCount: $outcome['already_reconciled_skipped'],
            unmatchedTransactionIds: $outcome['unmatched_transaction_ids'],
            paidButNotSettled: $this->paidButNotSettled($parsed->dateFrom, $parsed->dateTo),
        );
    }

    /**
     * §16 item 120: attaches the original file to a settlement uploaded
     * before files were kept. Only a file whose dates and file totals are
     * exactly the ones recorded is accepted; nothing else on the row moves.
     */
    public function attachOriginal(PaymentSettlement $settlement, UploadedFile $file): PaymentSettlement
    {
        if ($settlement->file_path !== null) {
            throw ValidationException::withMessages(['file' => ["Settlement #{$settlement->id} already has its original file."]]);
        }

        $parsed = $this->parse($file);

        $matches = $parsed->dateFrom->toDateString() === $settlement->date_from->toDateString()
            && $parsed->dateTo->toDateString() === $settlement->date_to->toDateString()
            && $parsed->fileGrossSen === $settlement->file_gross_sen
            && $parsed->fileFeeSen === $settlement->file_fee_sen
            && $parsed->fileNetSen === $settlement->file_net_sen;

        if (! $matches) {
            throw ValidationException::withMessages(['file' => ["This file's dates or totals don't match settlement #{$settlement->id}, so it isn't its original."]]);
        }

        try {
            return AccountingFileStore::storeThen(
                $file,
                config('filesystems.accounting_disk'),
                'accounting/chip-settlements',
                $settlement->date_to->toDateString(),
                function (?string $path) use ($settlement, $file) {
                    $settlement->update(['file_path' => $path, 'file_sha256' => hash_file('sha256', $file->getRealPath())]);

                    return $settlement->fresh();
                },
            );
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['file' => ['This exact file is already attached to another settlement.']]);
        }
    }

    /**
     * @param  array<int, ParsedSettlementTransaction>  $newTransactions
     * @return array{settlement: PaymentSettlement, newly_matched: int, newly_unmatched: int, already_reconciled_skipped: int, unmatched_transaction_ids: list<string>}
     */
    private function record(ParsedSettlementFile $parsed, array $newTransactions, UploadedFile $file, ?string $path, ?int $adminUserId, int $alreadyReconciledSkipped): array
    {
        $settlement = PaymentSettlement::query()->create([
            'date_from' => $parsed->dateFrom->toDateString(),
            'date_to' => $parsed->dateTo->toDateString(),
            'matched_gross_sen' => 0,
            'matched_fee_sen' => 0,
            'matched_net_sen' => 0,
            'file_gross_sen' => $parsed->fileGrossSen,
            'file_fee_sen' => $parsed->fileFeeSen,
            'file_net_sen' => $parsed->fileNetSen,
            'status' => 'pending',
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_sha256' => hash_file('sha256', $file->getRealPath()),
            'admin_user_id' => $adminUserId,
        ]);

        $newlyMatched = 0;
        $newlyUnmatched = 0;
        $unmatchedTransactionIds = [];
        $matchedGross = 0;
        $matchedFee = 0;
        $hasGrossMismatch = false;

        foreach ($newTransactions as $transaction) {
            $match = $this->resolveMatch($transaction->transactionId);

            try {
                ChipSettledTransaction::query()->create([
                    'transaction_id' => $transaction->transactionId,
                    'matched_type' => $match['type'] ?? null,
                    'matched_id' => $match['id'] ?? null,
                    'local_gross_sen' => $match['gross'] ?? null,
                    'local_fee_sen' => $match['fee'] ?? null,
                    'amount_sen' => $transaction->amountSen,
                    'fee_sen' => $transaction->feeSen,
                    'net_amount_sen' => $transaction->netAmountSen,
                    'acquirer' => $transaction->acquirer,
                    'settled_on' => $transaction->settledOn->toDateString(),
                    'payment_settlement_id' => $settlement->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another upload reconciled it between the pre-check and here.
                $alreadyReconciledSkipped++;

                continue;
            }

            if ($match === null) {
                $newlyUnmatched++;
                $unmatchedTransactionIds[] = $transaction->transactionId;

                continue;
            }

            $newlyMatched++;
            $matchedGross += $match['gross'];
            $matchedFee += $match['fee'];

            if ($match['gross'] !== $transaction->amountSen) {
                $hasGrossMismatch = true;
            }
        }

        if ($newlyMatched + $newlyUnmatched === 0) {
            // Everything was reconciled by a concurrent upload: roll this one back.
            $this->refuseNothingNew(array_map(fn (ParsedSettlementTransaction $t) => $t->transactionId, $parsed->transactions));
        }

        // An unmatched transaction is just as much a reconciliation
        // failure as a gross mismatch — real money CHIP reports as
        // settled that this platform can't identify at all. Found live:
        // a settlement where every transaction was unmatched (0 gross
        // to compare against) showed a clean "matched" status, which
        // was misleading — the detail screen's own per-row Tag colors
        // (unmatched/mismatch/clean) tell them apart for the founder,
        // `status` itself just needs to say "something needs a look."
        $settlement->update([
            'matched_gross_sen' => $matchedGross,
            'matched_fee_sen' => $matchedFee,
            'matched_net_sen' => $matchedGross - $matchedFee,
            'status' => $hasGrossMismatch || $newlyUnmatched > 0 ? 'variance' : 'matched',
        ]);

        return [
            'settlement' => $settlement,
            'newly_matched' => $newlyMatched,
            'newly_unmatched' => $newlyUnmatched,
            'already_reconciled_skipped' => $alreadyReconciledSkipped,
            'unmatched_transaction_ids' => $unmatchedTransactionIds,
        ];
    }

    /** A file that isn't a readable CHIP export is the uploader's error (422), never a 500. */
    private function parse(UploadedFile $file): ParsedSettlementFile
    {
        try {
            return $this->parser->parse($file->getRealPath());
        } catch (RuntimeException|OpenSpoutException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }
    }

    /** @param  list<string>  $transactionIds */
    private function refuseNothingNew(array $transactionIds): never
    {
        $settlementIds = ChipSettledTransaction::query()
            ->whereIn('transaction_id', $transactionIds)
            ->distinct()
            ->orderBy('payment_settlement_id')
            ->pluck('payment_settlement_id')
            ->map(fn (int $id) => "#{$id}")
            ->implode(', ');

        throw ValidationException::withMessages(['file' => [
            "Every transaction in this file is already reconciled (settlement {$settlementIds}). Nothing was recorded.",
        ]]);
    }

    private function resolveMatch(string $transactionId): ?array
    {
        if ($order = Order::query()->where('payment_ref', $transactionId)->where('is_test', false)->first(['id', 'final_amount', 'transaction_fee'])) {
            return [
                'type' => ChipTransactionMatchType::Order,
                'id' => $order->id,
                'gross' => $order->final_amount,
                'fee' => $order->transaction_fee,
            ];
        }

        if ($attempt = MembershipCheckoutAttempt::query()->where('payment_ref', $transactionId)->first(['id', 'total_charged_sen', 'fee_sen'])) {
            return [
                'type' => ChipTransactionMatchType::MembershipCheckoutAttempt,
                'id' => $attempt->id,
                'gross' => $attempt->total_charged_sen,
                'fee' => $attempt->total_charged_sen - $attempt->fee_sen,
            ];
        }

        if ($topup = WalletTopupAttempt::query()->where('chip_payment_ref', $transactionId)->first(['id', 'total_charged_sen', 'amount_sen'])) {
            return [
                'type' => ChipTransactionMatchType::WalletTopupAttempt,
                'id' => $topup->id,
                'gross' => $topup->total_charged_sen,
                'fee' => $topup->total_charged_sen - $topup->amount_sen,
            ];
        }

        return null;
    }

    /**
     * ADR-083 decision 7's "our-record-paid-but-not-settled" exception —
     * a CHIP-paid record in this window whose `transaction_id` has never
     * appeared in ANY settlement upload to date, not just this one.
     * Public (not just called from `ingest()`) so the settlement detail
     * screen can recompute it live on every view — a transaction still
     * genuinely awaiting CHIP's own T+1/T+2 settlement resolves itself
     * once a later file covers it, and a founder revisiting an older
     * settlement days later should see the current answer, not a stale
     * one. This is purely informational — it is never folded into
     * `status` (see this ADR's own addendum for why: it describes
     * *other* transactions entirely absent from this file, not a
     * disagreement about one that's actually in it).
     *
     * @return array<int, array{reference: string, amount_sen: int}>
     */
    public function paidButNotSettled(Carbon $dateFrom, Carbon $dateTo): array
    {
        // The window's dates are KL calendar dates (CHIP's own settlement
        // day); item 63 — they used to be read as UTC days, cutting at
        // 08:00 KL. Exclusive upper bound, same as Reports.
        [$from, $toExclusive] = app(ReportService::class)->dateRangeFromDates($dateFrom->toDateString(), $dateTo->toDateString());

        return $this->unsettled($from, $toExclusive, ChipSettledTransaction::query()->pluck('transaction_id')->all());
    }

    /**
     * ADR-083 2026-10-10 addendum, decision 13 — every CHIP payment made
     * before `$toExclusive` that no settlement had paid out by then (a
     * settlement's `settled_on` is a KL date). The month close's "CHIP paid
     * but not settled" asset.
     *
     * @return array<int, array{reference: string, amount_sen: int}>
     */
    public function paidButNotSettledBefore(Carbon $toExclusive): array
    {
        $klDay = $toExclusive->copy()->setTimezone(MalaysiaTime::TIMEZONE)->toDateString();

        return $this->unsettled(null, $toExclusive, ChipSettledTransaction::query()->where('settled_on', '<', $klDay)->pluck('transaction_id')->all());
    }

    /**
     * @param  list<string>  $settledRefs
     * @return array<int, array{reference: string, amount_sen: int}>
     */
    private function unsettled(?CarbonInterface $from, CarbonInterface $toExclusive, array $settledRefs): array
    {
        $orders = Order::query()
            ->where('payment_gateway', 'chip')
            ->where('payment_status', PaymentStatus::Paid->value)
            ->where('is_test', false)
            ->when($from, fn ($q) => $q->where('paid_at', '>=', $from))->where('paid_at', '<', $toExclusive)
            ->whereNotIn('payment_ref', $settledRefs)
            ->get(['order_number', 'final_amount'])
            ->map(fn (Order $o) => ['reference' => $o->order_number, 'amount_sen' => $o->final_amount]);

        $memberships = MembershipCheckoutAttempt::query()
            ->where('status', MembershipCheckoutAttemptStatus::Paid->value)
            ->when($from, fn ($q) => $q->where('updated_at', '>=', $from))->where('updated_at', '<', $toExclusive)
            ->whereNotIn('payment_ref', $settledRefs)
            ->get(['subscription_number', 'total_charged_sen'])
            ->map(fn (MembershipCheckoutAttempt $m) => ['reference' => $m->subscription_number, 'amount_sen' => $m->total_charged_sen]);

        $walletTopups = WalletTopupAttempt::query()
            ->where('status', WalletTopupAttemptStatus::Paid->value)
            ->when($from, fn ($q) => $q->where('updated_at', '>=', $from))->where('updated_at', '<', $toExclusive)
            ->whereNotIn('chip_payment_ref', $settledRefs)
            ->get(['reference', 'total_charged_sen'])
            ->map(fn (WalletTopupAttempt $w) => ['reference' => $w->reference, 'amount_sen' => $w->total_charged_sen]);

        return $orders->concat($memberships)->concat($walletTopups)->values()->all();
    }
}
