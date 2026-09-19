<?php

namespace App\Services\Accounting;

use App\Models\ChipSettledTransaction;
use App\Models\MembershipCheckoutAttempt;
use App\Models\Order;
use App\Models\PaymentSettlement;
use App\Models\WalletTopupAttempt;
use App\Services\Membership\MembershipCheckoutAttemptStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Reseller\WalletTopupAttemptStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    public function ingest(string $filePath, string $originalFilename, ?int $adminUserId): SettlementIngestResult
    {
        $parsed = $this->parser->parse($filePath);

        $alreadyReconciled = ChipSettledTransaction::query()
            ->whereIn('transaction_id', array_map(fn (ParsedSettlementTransaction $t) => $t->transactionId, $parsed->transactions))
            ->pluck('transaction_id')
            ->all();

        $newTransactions = array_filter(
            $parsed->transactions,
            fn (ParsedSettlementTransaction $t) => ! in_array($t->transactionId, $alreadyReconciled, true),
        );

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
            'original_filename' => $originalFilename,
            'admin_user_id' => $adminUserId,
        ]);

        $newlyMatched = 0;
        $newlyUnmatched = 0;
        $alreadyReconciledSkipped = count($alreadyReconciled);
        $unmatchedTransactionIds = [];
        $matchedGross = 0;
        $matchedFee = 0;
        $recordedCount = 0;
        $hasGrossMismatch = false;

        DB::transaction(function () use (
            $newTransactions,
            $settlement,
            &$newlyMatched,
            &$newlyUnmatched,
            &$alreadyReconciledSkipped,
            &$unmatchedTransactionIds,
            &$matchedGross,
            &$matchedFee,
            &$recordedCount,
            &$hasGrossMismatch,
        ) {
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
                    // A concurrent upload (a double-click, two admin
                    // tabs) inserted this exact transaction_id between
                    // our pre-check and this insert — treat it exactly
                    // like the pre-check's own "already reconciled"
                    // case rather than crashing the whole ingest.
                    $alreadyReconciledSkipped++;

                    continue;
                }

                $recordedCount++;

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
        });

        // An unmatched transaction is just as much a reconciliation
        // failure as a gross mismatch — real money CHIP reports as
        // settled that this platform can't identify at all. Found live:
        // a settlement where every transaction was unmatched (0 gross
        // to compare against) showed a clean "matched" status, which
        // was misleading — the detail screen's own per-row Tag colors
        // (unmatched/mismatch/clean) tell them apart for the founder,
        // `status` itself just needs to say "something needs a look."
        $status = match (true) {
            $recordedCount === 0 => 'pending',
            $hasGrossMismatch || $newlyUnmatched > 0 => 'variance',
            default => 'matched',
        };

        $settlement->update([
            'matched_gross_sen' => $matchedGross,
            'matched_fee_sen' => $matchedFee,
            'matched_net_sen' => $matchedGross - $matchedFee,
            'status' => $status,
        ]);

        return new SettlementIngestResult(
            settlement: $settlement->fresh(),
            newlyMatchedCount: $newlyMatched,
            newlyUnmatchedCount: $newlyUnmatched,
            alreadyReconciledSkippedCount: $alreadyReconciledSkipped,
            unmatchedTransactionIds: $unmatchedTransactionIds,
            paidButNotSettled: $this->paidButNotSettled($parsed->dateFrom, $parsed->dateTo),
        );
    }

    /**
     * @return array{type: ChipTransactionMatchType, id: int, gross: int, fee: int}|null
     */
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
        $from = $dateFrom->copy()->startOfDay();
        $to = $dateTo->copy()->endOfDay();
        $settledRefs = ChipSettledTransaction::query()->pluck('transaction_id')->all();

        $orders = Order::query()
            ->where('payment_gateway', 'chip')
            ->where('payment_status', PaymentStatus::Paid->value)
            ->where('is_test', false)
            ->whereBetween('paid_at', [$from, $to])
            ->whereNotIn('payment_ref', $settledRefs)
            ->get(['order_number', 'final_amount'])
            ->map(fn (Order $o) => ['reference' => $o->order_number, 'amount_sen' => $o->final_amount]);

        $memberships = MembershipCheckoutAttempt::query()
            ->where('status', MembershipCheckoutAttemptStatus::Paid->value)
            ->whereBetween('updated_at', [$from, $to])
            ->whereNotIn('payment_ref', $settledRefs)
            ->get(['subscription_number', 'total_charged_sen'])
            ->map(fn (MembershipCheckoutAttempt $m) => ['reference' => $m->subscription_number, 'amount_sen' => $m->total_charged_sen]);

        $walletTopups = WalletTopupAttempt::query()
            ->where('status', WalletTopupAttemptStatus::Paid->value)
            ->whereBetween('updated_at', [$from, $to])
            ->whereNotIn('chip_payment_ref', $settledRefs)
            ->get(['reference', 'total_charged_sen'])
            ->map(fn (WalletTopupAttempt $w) => ['reference' => $w->reference, 'amount_sen' => $w->total_charged_sen]);

        return $orders->concat($memberships)->concat($walletTopups)->values()->all();
    }
}
