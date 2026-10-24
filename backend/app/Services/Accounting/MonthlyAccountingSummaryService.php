<?php

namespace App\Services\Accounting;

use App\Models\BudgetEnvelopePosting;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\PaymentSettlement;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Report\ReportService;
use App\Support\MalaysiaTime;
use Illuminate\Support\Carbon;

/**
 * ADR-083 decision 8, fills ADR-110 PR-B — the read-only journal lines
 * Farres copies into the external accounting SaaS (Bukku) each month.
 * Every figure here is a plain derived query for one closed period —
 * no persistence, nothing cached, always computed fresh from the
 * underlying tables (`orders`/`ledger_entries`/`supplier_transfers`/
 * `supplier_ledger_entries`/`membership_fee_records`/`vouchers`/
 * `payment_settlements`).
 *
 * Revenue/COGS recognition follows the same `orders.is_test = false`
 * discipline `ReportService` already established (ADR-086), and the
 * same "profit is ledger-sourced, never `Order.affiliate_profit`
 * directly" rule (that column is checkout-time-stamped, before the
 * delivery outcome is known) — see `ReportService`'s own doc comment.
 */
final class MonthlyAccountingSummaryService
{
    public function __construct(
        private readonly RecognisedRevenue $revenue = new RecognisedRevenue,
        private readonly FundedCostOfSales $costOfSales = new FundedCostOfSales,
    ) {}

    /**
     * Every value is sen except `cogs_fallback_order_count`.
     *
     * @return array<string, int>
     */
    public function forPeriod(int $year, int $month): array
    {
        // Add the month in KL time, then convert: adding it after the UTC
        // shift ended the period one KL day early whenever the previous
        // month is shorter (31 Oct KL fell into no month at all).
        [$from, $toExclusive] = self::monthBounds($year, $month);

        $lines = [
            'sales_revenue_sen' => $this->salesRevenue($from, $toExclusive),
            'membership_revenue_sen' => $this->membershipRevenue($from, $toExclusive),
            ...$this->costOfSales->forPeriod($from, $toExclusive),
            'payment_processing_gain_loss_sen' => $this->paymentProcessingGainLoss($from, $toExclusive),
            'supplier_prepaid_topup_sen' => $this->supplierPrepaidTopup($from, $toExclusive),
            'bank_transfer_fees_sen' => $this->bankTransferFees($from, $toExclusive),
            'affiliate_commission_expense_sen' => $this->affiliateCommissionExpense($from, $toExclusive),
            'affiliate_tier_fees_sen' => $this->affiliateTierFees($from, $toExclusive),
            'voucher_breakage_sen' => $this->voucherBreakage($from, $toExclusive),
            'admin_issued_vouchers_sen' => $this->adminIssuedVouchers($from, $toExclusive),
            'supplier_manual_adjustments_sen' => $this->supplierManualAdjustments($from, $toExclusive),
            'failed_order_credit_issued_sen' => $this->failedOrderCreditIssued($from, $toExclusive),
            'reseller_wallet_balance_sen' => $this->resellerWalletBalance($toExclusive),
            'envelope_manual_expenses_sen' => array_sum($this->envelopeExpensesByCategory($year, $month)),
        ];

        return [...$lines, 'operating_profit_sen' => self::operatingProfit($lines)];
    }

    /**
     * ADR-083 2026-10-10 addendum, decision 11 — what a month close
     * allocates. A new line above must say which side it falls on.
     * COGS is at funded cost (2026-10-17 addendum), so no FX true-up is
     * added back; the COGS bridge lines and drawdowns not in COGS are
     * information only. Deliberately left out: compensation vouchers (a liability — the cash
     * was received and kept) and envelope expenses (they already reduced
     * their envelope; subtracting here again counts them twice). Capital
     * movements (supplier top-up, wallet balance) are not P&L at all.
     *
     * @param  array<string, int>  $lines
     */
    public static function operatingProfit(array $lines): int
    {
        return $lines['sales_revenue_sen']
            + $lines['membership_revenue_sen']
            - $lines['cogs_sen']
            + $lines['payment_processing_gain_loss_sen']
            - $lines['bank_transfer_fees_sen']
            - $lines['affiliate_commission_expense_sen']
            + $lines['affiliate_tier_fees_sen']
            + $lines['voucher_breakage_sen']
            - $lines['admin_issued_vouchers_sen']
            + $lines['supplier_manual_adjustments_sen'];
    }

    /**
     * The whole KL month as UTC instants. Add the month in KL time, then
     * convert: adding it after the UTC shift ended the period one KL day
     * early whenever the previous month is shorter (31 Oct KL fell into no
     * month at all).
     *
     * @return array{0: Carbon, 1: Carbon} [from, toExclusive]
     */
    public static function monthBounds(int $year, int $month): array
    {
        $monthStart = Carbon::create($year, $month, 1, 0, 0, 0, MalaysiaTime::TIMEZONE);

        return [$monthStart->copy()->setTimezone('UTC'), $monthStart->copy()->addMonthNoOverflow()->setTimezone('UTC')];
    }

    /**
     * Decision 11 (Q19) — the month's Envelope Ledger expenses (plain and
     * director-paid) by sub-category, an information line only. Scoped by
     * the posting's KL `transaction_date`; a void is dated with its
     * original, so a voided expense nets to nothing.
     *
     * @return array<string, int> expense_category value => sen, positive
     */
    public function envelopeExpensesByCategory(int $year, int $month): array
    {
        $first = Carbon::create($year, $month, 1)->toDateString();
        $last = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        return BudgetEnvelopePosting::query()
            ->whereIn('type', [EnvelopePostingType::Expense->value, EnvelopePostingType::DirectorPaidExpense->value])
            ->whereDate('transaction_date', '>=', $first)
            ->whereDate('transaction_date', '<=', $last)
            ->get(['expense_category', 'amount_sen'])
            ->groupBy(fn (BudgetEnvelopePosting $p) => $p->expense_category->value)
            ->map(fn ($postings) => (int) $postings->sum('amount_sen'))
            ->filter()
            ->sortKeys()
            ->all();
    }

    /**
     * Decision 11 (Q21) — a voucher that expired this month keeps its
     * remaining balance as income. Expiry is lazy (the status never flips),
     * so `expires_at` alone decides. A sandbox order's voucher was never
     * customer cash.
     *
     * ADR-127 P1: also a revoked voucher's balance at the moment it was
     * revoked, in that month. And the balance at the moment it expired, not today's
     * `remaining`; a redemption restored onto it after it expired is
     * breakage of the month of the restore. So a later restore never
     * changes an earlier month.
     */
    private function voucherBreakage(Carbon $from, Carbon $toExclusive): int
    {
        $notSandbox = fn ($q) => $q->whereNull('order_id')->orWhereHas('sourceOrder', fn ($o) => $o->where('is_test', false));

        $atExpiry = Voucher::query()
            ->where('expires_at', '>=', $from)
            ->where('expires_at', '<', $toExclusive)
            ->where($notSandbox)
            ->with(['redemptions', 'mergeAsSource'])
            ->get()
            ->sum(fn (Voucher $v) => $v->balanceAsOf($v->expires_at));

        $restoredAfterExpiry = VoucherRedemption::query()
            ->where('status', 'restored')
            ->where('restored_at', '>=', $from)
            ->where('restored_at', '<', $toExclusive)
            ->whereHas('voucher', fn ($v) => $v->whereColumn('vouchers.expires_at', '<', 'voucher_redemptions.restored_at')->where($notSandbox))
            ->sum('restored_amount');

        // A revoked voucher's unspent balance, in the month it was revoked.
        $revoked = Voucher::query()
            ->where('revoked_at', '>=', $from)
            ->where('revoked_at', '<', $toExclusive)
            ->where($notSandbox)
            ->with(['redemptions', 'mergeAsSource'])
            ->get()
            ->sum(fn (Voucher $v) => $v->balanceAsOf($v->revoked_at));

        return (int) $atExpiry + (int) $restoredAfterExpiry + (int) $revoked;
    }

    /**
     * Decision 11 — a voucher an admin issues with no source order
     * (ADR-004 Path A) is an expense when issued. A merge target is not a new voucher: its sources
     * were already counted when they were issued.
     */
    private function adminIssuedVouchers(Carbon $from, Carbon $toExclusive): int
    {
        return (int) Voucher::query()
            ->whereNull('order_id')
            ->whereDoesntHave('mergesAsTarget')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->sum('amount');
    }

    /**
     * Decision 11 (Q26) — a partial supplier-balance correction recorded
     * this month, in MYR at the month-end rate. A voided transfer's
     * adjustments are reversed by its void, so they are skipped.
     */
    private function supplierManualAdjustments(Carbon $from, Carbon $toExclusive): int
    {
        $rows = SupplierLedgerEntry::query()
            ->where('type', SupplierLedgerEntryType::ManualAdjustment->value)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->whereIn('reference_id', SupplierTransfer::query()->whereNull('voided_at')->select('id'))
            ->selectRaw('supplier_id, SUM(amount) as total')
            ->groupBy('supplier_id')
            ->get();

        $lastDay = self::klLastDay($toExclusive);

        return (int) $rows->sum(fn ($row) => (int) round(((float) $row->total) * ($this->costOfSales->weightedAverageRate((int) $row->supplier_id, $lastDay) ?? 0.0) * 100));
    }

    /** The KL calendar date of the last day before an exclusive UTC bound. */
    private static function klLastDay(Carbon $toExclusive): string
    {
        return $toExclusive->copy()->setTimezone(MalaysiaTime::TIMEZONE)->subDay()->toDateString();
    }

    /**
     * 2026-09-30 addendum (Bucket C, decision 9) — "money you hold for
     * resellers, which isn't yours." The balance at the month end, from
     * the append-only wallet ledger (ADR-127 P1). It used to be today's
     * balance, which moved a closed month's figure with every later top-up
     * or order. For the current month it is today's balance.
     */
    private function resellerWalletBalance(Carbon $toExclusive): int
    {
        return (int) LedgerEntry::query()
            ->where('owner_type', LedgerOwnerType::ResellerWallet->value)
            ->where('created_at', '<', $toExclusive)
            ->sum('amount');
    }

    /**
     * ADR-083 decision 8 — "Sales revenue (Σ selling_price delivered)",
     * plus settled partial orders net of compensation (ADR-094 decision
     * 41). One definition, shared with the Reports Bridge to Accounting
     * (ADR-104 2026-10-08 addendum R7).
     */
    private function salesRevenue(Carbon $from, Carbon $toExclusive): int
    {
        return $this->revenue->revenueSen($from, $toExclusive);
    }

    /**
     * ADR-127 decision 7: one source, the platform's `membership_fee` ledger
     * rows, which Reports and the Report Assistant already read. A waived
     * (RM0) fee record writes no ledger row, so the totals are equal.
     */
    private function membershipRevenue(Carbon $from, Carbon $toExclusive): int
    {
        return (int) LedgerEntry::query()
            ->where('owner_type', LedgerOwnerType::Platform->value)
            ->where('type', 'membership_fee')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->sum('amount');
    }

    /**
     * ADR-083 decision 7 — "Σ transaction_fee charged − Σ CHIP Fee
     * actual", from every settlement batch whose window ends inside this
     * month (a batch is typically weekly, per this ADR's own recommended
     * cadence — several may fall inside one month). Keyed on `date_to`
     * alone: requiring the whole window inside the month made a batch
     * that crosses a month end count in neither month.
     * `matched_fee_sen` (not the old `expected_fee_sen`, dropped by this
     * ADR's own same-day addendum) sums our own fee assumption ONLY for
     * the transactions each settlement actually matched — the same fix
     * that made `status` reliable also makes this line correct.
     */
    private function paymentProcessingGainLoss(Carbon $from, Carbon $toExclusive): int
    {
        $totals = PaymentSettlement::query()
            // Settlement windows are KL calendar dates, so compare against
            // the KL dates, not the UTC instants' dates.
            ->where('date_to', '>=', $from->copy()->setTimezone(MalaysiaTime::TIMEZONE)->toDateString())
            ->where('date_to', '<', $toExclusive->copy()->setTimezone(MalaysiaTime::TIMEZONE)->toDateString())
            ->selectRaw('COALESCE(SUM(matched_fee_sen), 0) as matched_fee, COALESCE(SUM(file_fee_sen), 0) as file_fee')
            ->first();

        return (int) $totals->matched_fee - (int) $totals->file_fee;
    }

    /**
     * 2026-09-30 audit fix: used to be `SUM(amount_myr_sent) +
     * SUM(fee_myr)` — capital sent to the supplier bundled with our own
     * Wise/Airwallex fee for moving it. Now capital-only; the fee gets
     * its own line (`bankTransferFees()`) so a founder manually copying
     * these two lines into the external accounting SaaS each month adds
     * two genuinely distinct amounts, never double-counts the fee
     * portion by summing a line that already includes it plus a second
     * line for the same fee.
     */
    private function supplierPrepaidTopup(Carbon $from, Carbon $toExclusive): int
    {
        return (int) SupplierTransfer::query()
            ->whereNull('voided_at')
            ->where('transferred_on', '>=', $from->copy()->setTimezone(MalaysiaTime::TIMEZONE)->toDateString())
            ->where('transferred_on', '<', $toExclusive->copy()->setTimezone(MalaysiaTime::TIMEZONE)->toDateString())
            ->sum('amount_myr_sent');
    }

    /**
     * 2026-09-30 audit fix — split out of `supplierPrepaidTopup()`: our
     * own Wise/Airwallex fee for sending capital to a supplier, a real
     * bank-charge expense distinct from the capital movement itself.
     */
    private function bankTransferFees(Carbon $from, Carbon $toExclusive): int
    {
        return (int) SupplierTransfer::query()
            ->whereNull('voided_at')
            ->where('transferred_on', '>=', $from->copy()->setTimezone(MalaysiaTime::TIMEZONE)->toDateString())
            ->where('transferred_on', '<', $toExclusive->copy()->setTimezone(MalaysiaTime::TIMEZONE)->toDateString())
            ->sum('fee_myr');
    }

    /**
     * ADR-086's "profit is ledger-sourced, never `Order.affiliate_profit`
     * directly" rule — same join shape as `ReportService::profitTotals()`.
     * Filed by `recognised_at`, with the revenue it is earned on (ADR-127).
     */
    private function affiliateCommissionExpense(Carbon $from, Carbon $toExclusive): int
    {
        return (int) LedgerEntry::query()
            ->join('orders', function ($join) {
                $join->on('orders.id', '=', 'ledger_entries.reference_id')
                    ->where('ledger_entries.reference_type', '=', 'order');
            })
            ->where('ledger_entries.type', 'order_profit')
            ->where('ledger_entries.owner_type', 'affiliate')
            ->where('orders.is_test', false)
            ->where('orders.recognised_at', '>=', $from)
            ->where('orders.recognised_at', '<', $toExclusive)
            ->sum('ledger_entries.amount');
    }

    /**
     * ADR-083 2026-10-08 addendum — tier fees deducted from affiliates'
     * earnings (ADR-056). No cash moves, so neither the bank statement nor
     * any other line here shows them. Neutral on purpose: revenue vs
     * contra-commission is the external reviewer's call (PRD §16).
     */
    private function affiliateTierFees(Carbon $from, Carbon $toExclusive): int
    {
        return -(int) LedgerEntry::query()
            ->where('owner_type', LedgerOwnerType::Affiliate->value)
            ->where('type', 'affiliate_tier_fee')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->sum('amount');
    }

    /**
     * Store credit issued for a failed or partial order (ADR-004 Path B):
     * the customer already paid, so this is a liability, not an expense.
     * Tied to an order, which a merge result never is, so a merge is not
     * counted as new credit.
     */
    private function failedOrderCreditIssued(Carbon $from, Carbon $toExclusive): int
    {
        return (int) Voucher::query()
            ->whereHas('sourceOrder', fn ($o) => $o->where('is_test', false))
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->sum('amount');
    }
}
