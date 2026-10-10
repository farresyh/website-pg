<?php

namespace App\Services\Accounting;

use App\Models\BudgetEnvelopePosting;
use App\Models\LedgerEntry;
use App\Models\MembershipFeeRecord;
use App\Models\Order;
use App\Models\PaymentSettlement;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use App\Models\Voucher;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Order\DeliveryStatus;
use App\Services\Report\ReportService;
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
    public function __construct(private readonly RecognisedRevenue $revenue = new RecognisedRevenue) {}

    /**
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
            'cogs_sen' => $this->cogs($from, $toExclusive),
            'payment_processing_gain_loss_sen' => $this->paymentProcessingGainLoss($from, $toExclusive),
            'supplier_prepaid_topup_sen' => $this->supplierPrepaidTopup($from, $toExclusive),
            'bank_transfer_fees_sen' => $this->bankTransferFees($from, $toExclusive),
            'supplier_prepaid_fx_variance_sen' => $this->supplierPrepaidFxVarianceTrueUp($from, $toExclusive),
            'affiliate_commission_expense_sen' => $this->affiliateCommissionExpense($from, $toExclusive),
            'affiliate_tier_fees_sen' => $this->affiliateTierFees($from, $toExclusive),
            'voucher_breakage_sen' => $this->voucherBreakage($from, $toExclusive),
            'goodwill_vouchers_issued_sen' => $this->goodwillVouchersIssued($from, $toExclusive),
            'supplier_manual_adjustments_sen' => $this->supplierManualAdjustments($from, $toExclusive),
            'voucher_liability_issued_sen' => $this->voucherLiabilityIssued($from, $toExclusive),
            'reseller_wallet_balance_sen' => $this->resellerWalletBalance(),
            'envelope_manual_expenses_sen' => array_sum($this->envelopeExpensesByCategory($year, $month)),
        ];

        return [...$lines, 'operating_profit_sen' => self::operatingProfit($lines)];
    }

    /**
     * ADR-083 2026-10-10 addendum, decision 11 — what a month close
     * allocates. A new line above must say which side it falls on.
     * Deliberately left out: compensation vouchers (a liability — the cash
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
            + $lines['supplier_prepaid_fx_variance_sen']
            + $lines['payment_processing_gain_loss_sen']
            - $lines['bank_transfer_fees_sen']
            - $lines['affiliate_commission_expense_sen']
            + $lines['affiliate_tier_fees_sen']
            + $lines['voucher_breakage_sen']
            - $lines['goodwill_vouchers_issued_sen']
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
        $monthStart = Carbon::create($year, $month, 1, 0, 0, 0, ReportService::TIMEZONE);

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
     */
    private function voucherBreakage(Carbon $from, Carbon $toExclusive): int
    {
        return (int) Voucher::query()
            ->where('expires_at', '>=', $from)
            ->where('expires_at', '<', $toExclusive)
            ->where(fn ($q) => $q->whereNull('order_id')->orWhereHas('sourceOrder', fn ($o) => $o->where('is_test', false)))
            ->sum('remaining');
    }

    /**
     * Decision 11 — goodwill (ADR-004 Path A: no source order) is an
     * expense when issued. A merge target is not new goodwill: its sources
     * were already counted when they were issued.
     */
    private function goodwillVouchersIssued(Carbon $from, Carbon $toExclusive): int
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

        return (int) $rows->sum(fn ($row) => (int) round(((float) $row->total) * ($this->weightedAverageRate((int) $row->supplier_id, $lastDay) ?? 0.0) * 100));
    }

    /** The KL calendar date of the last day before an exclusive UTC bound. */
    private static function klLastDay(Carbon $toExclusive): string
    {
        return $toExclusive->copy()->setTimezone(ReportService::TIMEZONE)->subDay()->toDateString();
    }

    /**
     * 2026-09-30 addendum (Bucket C, decision 9) — "money you hold for
     * resellers, which isn't yours." Deliberately **not** a `$from`/
     * `$toExclusive`-scoped period figure like every other line here —
     * a wallet balance only ever has one real value, *now*; there is no
     * historical point-in-time snapshot mechanism (`ResellerWalletService
     * ::balance()` is a live `SUM(amount)` over `ledger_entries`, same
     * for every reseller combined here). Even when viewing a past
     * month's summary, this line shows today's real balance — the
     * frontend labels it with a live "as of" timestamp so it's never
     * mistaken for that period's own month-end figure. A true historical
     * snapshot would need a new scheduled snapshot job; not built since
     * this line's whole purpose (don't forget resellers' money isn't
     * yours) is already served by a current figure for a solo-founder's
     * once-a-month copy into the external SaaS.
     */
    private function resellerWalletBalance(): int
    {
        return (int) LedgerEntry::query()
            ->where('owner_type', LedgerOwnerType::ResellerWallet->value)
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

    private function cogs(Carbon $from, Carbon $toExclusive): int
    {
        return (int) Order::query()
            ->where('is_test', false)
            ->where('delivery_status', DeliveryStatus::Delivered->value)
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $toExclusive)
            ->sum('cost_price')
            + (int) $this->revenue->settledPartialOrders($from, $toExclusive)->sum(fn (Order $o) => $o->effectiveCostPriceSen());
    }

    private function membershipRevenue(Carbon $from, Carbon $toExclusive): int
    {
        return (int) MembershipFeeRecord::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->sum('amount_sen');
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
            ->where('date_to', '>=', $from->copy()->setTimezone(ReportService::TIMEZONE)->toDateString())
            ->where('date_to', '<', $toExclusive->copy()->setTimezone(ReportService::TIMEZONE)->toDateString())
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
            ->where('transferred_on', '>=', $from->copy()->setTimezone(ReportService::TIMEZONE)->toDateString())
            ->where('transferred_on', '<', $toExclusive->copy()->setTimezone(ReportService::TIMEZONE)->toDateString())
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
            ->where('transferred_on', '>=', $from->copy()->setTimezone(ReportService::TIMEZONE)->toDateString())
            ->where('transferred_on', '<', $toExclusive->copy()->setTimezone(ReportService::TIMEZONE)->toDateString())
            ->sum('fee_myr');
    }

    /**
     * ADR-083 decision 5 — "Σ orders.cost_price (delivered that month)
     * minus Σ (foreign drawn that month × weighted-average rate of that
     * supplier's transfers)". The weighted-average rate blends that
     * supplier's whole non-voided transfer history up to the month's last
     * KL day (the "true blended cost of funds" framing decision 5's own
     * rationale uses). Not later transfers: 2026-10-10 addendum, decision
     * 11 (Q24) — a closed month must stop moving on every new transfer.
     */
    private function supplierPrepaidFxVarianceTrueUp(Carbon $from, Carbon $toExclusive): int
    {
        $cogs = $this->cogs($from, $toExclusive);
        $lastDay = self::klLastDay($toExclusive);

        $drawdownsBySupplier = SupplierLedgerEntry::query()
            ->where('type', SupplierLedgerEntryType::OrderDrawdown->value)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->selectRaw('supplier_id, SUM(ABS(amount)) as foreign_drawn')
            ->groupBy('supplier_id')
            ->get();

        $foreignDrawnMyrSen = 0;

        foreach ($drawdownsBySupplier as $row) {
            $rate = $this->weightedAverageRate((int) $row->supplier_id, $lastDay);

            if ($rate === null) {
                continue; // no real transfer history yet for this supplier — nothing to true up against
            }

            $foreignDrawnMyrSen += (int) round(((float) $row->foreign_drawn) * $rate * 100);
        }

        return $cogs - $foreignDrawnMyrSen;
    }

    /**
     * MYR per unit of foreign currency, weighted by each transfer's own
     * net foreign amount received — `Σ amount_myr_sent / Σ net_foreign_received`
     * across every non-voided transfer for this supplier sent on or before
     * `$asOfDate` (a KL date). Public for the month close's supplier
     * prepaid figure (2026-10-10 addendum, decision 13).
     */
    public function weightedAverageRate(int $supplierId, string $asOfDate): ?float
    {
        $transfers = SupplierTransfer::query()
            ->where('supplier_id', $supplierId)
            ->whereNull('voided_at')
            ->where('transferred_on', '<=', $asOfDate)
            ->get(['amount_myr_sent', 'amount_foreign_received', 'supplier_fee']);

        $totalMyrSen = $transfers->sum('amount_myr_sent');
        $totalForeign = $transfers->sum(fn (SupplierTransfer $t) => (float) $t->netForeignReceived());

        if ($totalForeign <= 0.0) {
            return null;
        }

        return ($totalMyrSen / 100) / $totalForeign;
    }

    /**
     * ADR-086's "profit is ledger-sourced, never `Order.affiliate_profit`
     * directly" rule — same join shape as `ReportService::profitTotals()`.
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
            ->where('orders.paid_at', '>=', $from)
            ->where('orders.paid_at', '<', $toExclusive)
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

    private function voucherLiabilityIssued(Carbon $from, Carbon $toExclusive): int
    {
        return (int) Voucher::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->sum('amount');
    }
}
