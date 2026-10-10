<?php

namespace App\Services\Accounting;

use App\Models\BudgetEnvelopeEntry;
use App\Models\BudgetEnvelopePosting;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Report\ReportService;
use App\Services\Withdrawal\WithdrawalStatus;
use Illuminate\Support\Carbon;

/**
 * ADR-083 2026-10-10 addendum, decision 13 — "where is the money", as of
 * the end of one KL month: every asset and claim except the cash-account
 * balances, which the founder types in at the close. Not double-entry: each
 * figure is owned by another module and only read here, rebuilt as of the
 * month end from dated rows.
 *
 * Gap = cash accounts + Σ assets − Σ claims. A healthy month is near zero;
 * a remaining gap is timing (an order paid but drawn from the supplier only
 * next month, a transfer in transit) and gets a written note at the close.
 */
final class CashPositionService
{
    /** Decision 13: CHIP's fee for a payment not yet settled is estimated at RM1. */
    public const ESTIMATED_CHIP_FEE_SEN = 100;

    public function __construct(
        private readonly MonthlyAccountingSummaryService $summary,
        private readonly SettlementReconciliationService $settlements,
    ) {}

    /**
     * @param  int  $pendingAllocationSen  a profit allocation not posted yet (the close preview), counted as if it were
     * @return array{assets: array<string, int>, claims: array<string, int>, envelope_identity_sen: int, chip_unsettled_count: int}
     */
    public function asOf(int $year, int $month, int $pendingAllocationSen = 0): array
    {
        [, $toExclusive] = MonthlyAccountingSummaryService::monthBounds($year, $month);
        $lastDay = $toExclusive->copy()->setTimezone(ReportService::TIMEZONE)->subDay()->toDateString();
        $unsettled = $this->settlements->paidButNotSettledBefore($toExclusive);

        return [
            'assets' => [
                'supplier_prepaid_sen' => $this->supplierPrepaid($toExclusive, $lastDay),
                'chip_unsettled_net_sen' => (int) array_sum(array_column($unsettled, 'amount_sen')) - count($unsettled) * self::ESTIMATED_CHIP_FEE_SEN,
            ],
            'claims' => [
                'envelopes_sen' => $this->envelopes($lastDay) + $pendingAllocationSen,
                'reseller_wallets_sen' => $this->ledgerBalance(LedgerOwnerType::ResellerWallet, $toExclusive),
                'affiliate_earnings_sen' => $this->ledgerBalance(LedgerOwnerType::Affiliate, $toExclusive),
                'affiliate_withdrawals_unpaid_sen' => $this->withdrawalsApprovedNotPaid($toExclusive),
                'vouchers_outstanding_sen' => $this->vouchersOutstanding($toExclusive),
                'orders_undelivered_sen' => $this->ordersUndelivered($toExclusive),
            ],
            'envelope_identity_sen' => $this->envelopeIdentity($lastDay) + $pendingAllocationSen,
            'chip_unsettled_count' => count($unsettled),
        ];
    }

    /** @param array{assets: array<string, int>, claims: array<string, int>} $position */
    public static function gapSen(array $position, int $cashAccountsSen): int
    {
        return $cashAccountsSen + array_sum($position['assets']) - array_sum($position['claims']);
    }

    private function envelopes(string $lastDay): int
    {
        return (int) BudgetEnvelopeEntry::query()
            ->whereHas('posting', fn ($q) => $q->whereDate('transaction_date', '<=', $lastDay))
            ->sum('amount_sen');
    }

    /**
     * Decision 13's internal check: what the envelopes should hold, built
     * from posting headers by type rather than from their lines — loans +
     * share capital + allocated profit − expenses − repayments −
     * distributions. A director-paid expense adds its loan and its expense,
     * netting to zero. Differs from `envelopes()` only if a posting's lines
     * disagree with its own header.
     */
    private function envelopeIdentity(string $lastDay): int
    {
        return (int) BudgetEnvelopePosting::query()
            ->whereDate('transaction_date', '<=', $lastDay)
            ->get(['type', 'amount_sen'])
            ->sum(fn (BudgetEnvelopePosting $p) => match ($p->type) {
                EnvelopePostingType::Funding, EnvelopePostingType::ProfitAllocation => $p->amount_sen,
                EnvelopePostingType::Expense, EnvelopePostingType::Repayment, EnvelopePostingType::Distribution => -$p->amount_sen,
                EnvelopePostingType::Transfer, EnvelopePostingType::DirectorPaidExpense => 0,
            });
    }

    /**
     * The supplier's balance in its own currency as of the month end, in MYR
     * at the month-end blended rate. A top-up counts from the day the money
     * left the bank (`transferred_on`, decision 15), every other movement
     * from when it was recorded.
     */
    private function supplierPrepaid(Carbon $toExclusive, string $lastDay): int
    {
        $balances = SupplierLedgerEntry::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('type', '!=', SupplierLedgerEntryType::Topup->value)->where('created_at', '<', $toExclusive))
                ->orWhere(fn ($q) => $q->where('type', SupplierLedgerEntryType::Topup->value)
                    ->whereIn('reference_id', SupplierTransfer::query()->where('transferred_on', '<=', $lastDay)->select('id'))))
            ->selectRaw('supplier_id, SUM(amount) as balance')
            ->groupBy('supplier_id')
            ->get();

        // ponytail: a supplier with no transfer by the month end has no rate; its balance is then only drawdowns, valued at 0.
        return (int) $balances->sum(fn ($row) => (int) round(((float) $row->balance) * ($this->summary->weightedAverageRate((int) $row->supplier_id, $lastDay) ?? 0.0) * 100));
    }

    private function ledgerBalance(LedgerOwnerType $owner, Carbon $toExclusive): int
    {
        return (int) LedgerEntry::query()
            ->where('owner_type', $owner->value)
            ->where('created_at', '<', $toExclusive)
            ->sum('amount');
    }

    /** Debited from the affiliate's ledger at approval; the cash leaves only when the payout is marked completed. */
    private function withdrawalsApprovedNotPaid(Carbon $toExclusive): int
    {
        return (int) Withdrawal::query()
            ->where('owner_type', LedgerOwnerType::Affiliate->value)
            ->whereIn('status', [WithdrawalStatus::Approved->value, WithdrawalStatus::Completed->value])
            ->where(fn ($q) => $q->whereNull('processed_at')->orWhere('processed_at', '>=', $toExclusive))
            ->whereIn('id', LedgerEntry::query()
                ->where('type', 'withdrawal')
                ->where('reference_type', 'withdrawal')
                ->where('created_at', '<', $toExclusive)
                ->select('reference_id'))
            ->sum('amount');
    }

    /**
     * Every voucher balance a customer could still spend at the month end,
     * rebuilt from its redemptions (`remaining` is only today's figure). An
     * expired voucher is breakage, no longer a claim; a merged-away source
     * lives on in its merge target.
     */
    private function vouchersOutstanding(Carbon $toExclusive): int
    {
        return (int) Voucher::query()
            ->where('created_at', '<', $toExclusive)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $toExclusive))
            ->where(fn ($q) => $q->whereNull('order_id')->orWhereHas('sourceOrder', fn ($o) => $o->where('is_test', false)))
            ->with(['redemptions', 'mergeAsSource'])
            ->get()
            ->sum(function (Voucher $voucher) use ($toExclusive) {
                if ($voucher->mergeAsSource !== null && $voucher->mergeAsSource->created_at < $toExclusive) {
                    return 0;
                }

                $before = $voucher->redemptions->filter(fn (VoucherRedemption $r) => $r->created_at < $toExclusive);
                // A restore carries only `updated_at`, and it is written once.
                $restored = $before->filter(fn (VoucherRedemption $r) => $r->status === 'restored' && $r->updated_at < $toExclusive);

                return max(0, $voucher->amount - (int) $before->sum('amount') + (int) $restored->sum('restored_amount'));
            });
    }

    /**
     * Orders paid by the month end whose goods were neither delivered nor
     * compensated by then: the customer is owed the selling price (a voucher
     * or wallet balance spent on it moved into this claim when it was
     * spent). A partial delivery stays here until it is compensated.
     */
    private function ordersUndelivered(Carbon $toExclusive): int
    {
        $orders = Order::query()
            ->where('is_test', false)
            ->where('payment_status', PaymentStatus::Paid->value)
            ->where('paid_at', '<', $toExclusive)
            ->where(fn ($q) => $q->where('delivery_status', '!=', DeliveryStatus::Delivered->value)->orWhere('delivered_at', '>=', $toExclusive))
            ->with(['voucher', 'voucherRedemption'])
            ->get();

        $walletRefunds = Order::walletRefundEntriesFor($orders);

        return (int) $orders
            ->reject(fn (Order $o) => ($o->voucher !== null && $o->voucher->created_at < $toExclusive)
                || ($o->voucherRedemption?->status === 'restored' && $o->voucherRedemption->updated_at < $toExclusive)
                || (($walletRefunds[$o->id] ?? null) !== null && $walletRefunds[$o->id]->created_at < $toExclusive))
            ->sum('selling_price');
    }
}
