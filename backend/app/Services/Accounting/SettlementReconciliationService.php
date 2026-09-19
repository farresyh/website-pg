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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ADR-110 PR-B, fills ADR-083 decision 7 (plus the same day's addendum
 * closing a real gap in that decision — see `docs/adr.md`'s ADR-110
 * entry): ingests one CHIP settlement `.xlsx`, matches every
 * transaction row to whichever of the three CHIP-charging flows this
 * platform has (retail/affiliate order, self-serve membership
 * subscription, self-serve reseller wallet top-up), and computes this
 * platform's own "expected" figures for the file's date window so an
 * admin can see the gap between what CHIP settled and what this
 * platform's own records say should have arrived.
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

        [$expectedGross, $expectedFee, $expectedNet] = $this->computeExpected($parsed->dateFrom, $parsed->dateTo);

        $settlement = PaymentSettlement::query()->create([
            'date_from' => $parsed->dateFrom->toDateString(),
            'date_to' => $parsed->dateTo->toDateString(),
            'expected_gross_sen' => $expectedGross,
            'expected_fee_sen' => $expectedFee,
            'expected_net_sen' => $expectedNet,
            'file_gross_sen' => $parsed->fileGrossSen,
            'file_fee_sen' => $parsed->fileFeeSen,
            'file_net_sen' => $parsed->fileNetSen,
            'status' => 'pending',
            'original_filename' => $originalFilename,
            'admin_user_id' => $adminUserId,
        ]);

        $newlyMatched = 0;
        $newlyUnmatched = 0;
        $unmatchedTransactionIds = [];

        DB::transaction(function () use ($newTransactions, $settlement, &$newlyMatched, &$newlyUnmatched, &$unmatchedTransactionIds) {
            foreach ($newTransactions as $transaction) {
                $match = $this->resolveMatch($transaction->transactionId);

                if ($match === null) {
                    $newlyUnmatched++;
                    $unmatchedTransactionIds[] = $transaction->transactionId;
                } else {
                    $newlyMatched++;
                }

                ChipSettledTransaction::query()->create([
                    'transaction_id' => $transaction->transactionId,
                    'matched_type' => $match['type'] ?? null,
                    'matched_id' => $match['id'] ?? null,
                    'amount_sen' => $transaction->amountSen,
                    'fee_sen' => $transaction->feeSen,
                    'net_amount_sen' => $transaction->netAmountSen,
                    'acquirer' => $transaction->acquirer,
                    'settled_on' => $transaction->settledOn->toDateString(),
                    'payment_settlement_id' => $settlement->id,
                ]);
            }
        });

        return new SettlementIngestResult(
            settlement: $settlement,
            newlyMatchedCount: $newlyMatched,
            newlyUnmatchedCount: $newlyUnmatched,
            alreadyReconciledSkippedCount: count($alreadyReconciled),
            unmatchedTransactionIds: $unmatchedTransactionIds,
            paidButNotSettled: $this->paidButNotSettled($parsed->dateFrom, $parsed->dateTo),
        );
    }

    /**
     * @return array{type: ChipTransactionMatchType, id: int}|null
     */
    private function resolveMatch(string $transactionId): ?array
    {
        if ($order = Order::query()->where('payment_ref', $transactionId)->first(['id'])) {
            return ['type' => ChipTransactionMatchType::Order, 'id' => $order->id];
        }

        if ($attempt = MembershipCheckoutAttempt::query()->where('payment_ref', $transactionId)->first(['id'])) {
            return ['type' => ChipTransactionMatchType::MembershipCheckoutAttempt, 'id' => $attempt->id];
        }

        if ($topup = WalletTopupAttempt::query()->where('chip_payment_ref', $transactionId)->first(['id'])) {
            return ['type' => ChipTransactionMatchType::WalletTopupAttempt, 'id' => $topup->id];
        }

        return null;
    }

    /**
     * ADR-083 decision 7 — "expected net is Σ(total_charged − transaction_fee)
     * over every CHIP inflow in the window", across all three CHIP-charging
     * flows. `MembershipCheckoutAttempt`/`WalletTopupAttempt` have no
     * `paid_at` column (unlike `Order`) — `updated_at` on a `Paid` row is
     * the closest available proxy for "when it was actually paid".
     *
     * @return array{0: int, 1: int, 2: int} [gross_sen, fee_sen, net_sen]
     */
    private function computeExpected(Carbon $dateFrom, Carbon $dateTo): array
    {
        $from = $dateFrom->copy()->startOfDay();
        $to = $dateTo->copy()->endOfDay();

        $orders = Order::query()
            ->where('payment_gateway', 'chip')
            ->where('payment_status', PaymentStatus::Paid->value)
            ->where('is_test', false)
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(final_amount), 0) as gross, COALESCE(SUM(transaction_fee), 0) as fee')
            ->first();

        $memberships = MembershipCheckoutAttempt::query()
            ->where('status', MembershipCheckoutAttemptStatus::Paid->value)
            ->whereBetween('updated_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(total_charged_sen), 0) as gross, COALESCE(SUM(total_charged_sen - fee_sen), 0) as fee')
            ->first();

        $walletTopups = WalletTopupAttempt::query()
            ->where('status', WalletTopupAttemptStatus::Paid->value)
            ->whereBetween('updated_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(total_charged_sen), 0) as gross, COALESCE(SUM(total_charged_sen - amount_sen), 0) as fee')
            ->first();

        $gross = (int) $orders->gross + (int) $memberships->gross + (int) $walletTopups->gross;
        $fee = (int) $orders->fee + (int) $memberships->fee + (int) $walletTopups->fee;

        return [$gross, $fee, $gross - $fee];
    }

    /**
     * ADR-083 decision 7's "our-record-paid-but-not-settled" exception —
     * a CHIP-paid record in this window whose `transaction_id` has never
     * appeared in ANY settlement upload to date, not just this one.
     *
     * @return array<int, array{reference: string, amount_sen: int}>
     */
    private function paidButNotSettled(Carbon $dateFrom, Carbon $dateTo): array
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
