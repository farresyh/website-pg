<?php

namespace App\Services\Accounting;

use App\Models\LedgerEntry;
use App\Models\MembershipFeeRecord;
use App\Models\Order;
use App\Models\PaymentSettlement;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use App\Models\Voucher;
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
    /**
     * @return array<string, int>
     */
    public function forPeriod(int $year, int $month): array
    {
        $from = Carbon::create($year, $month, 1, 0, 0, 0, ReportService::TIMEZONE)->setTimezone('UTC');
        $toExclusive = $from->copy()->addMonthNoOverflow();

        return [
            'sales_revenue_sen' => $this->salesRevenue($from, $toExclusive),
            'membership_revenue_sen' => $this->membershipRevenue($from, $toExclusive),
            'cogs_sen' => $this->cogs($from, $toExclusive),
            'payment_processing_gain_loss_sen' => $this->paymentProcessingGainLoss($from, $toExclusive),
            'supplier_prepaid_topup_sen' => $this->supplierPrepaidTopup($from, $toExclusive),
            'supplier_prepaid_fx_variance_sen' => $this->supplierPrepaidFxVarianceTrueUp($from, $toExclusive),
            'affiliate_commission_expense_sen' => $this->affiliateCommissionExpense($from, $toExclusive),
            'voucher_liability_issued_sen' => $this->voucherLiabilityIssued($from, $toExclusive),
        ];
    }

    /**
     * ADR-083 decision 8 — "Sales revenue (Σ selling_price delivered)":
     * revenue is recognized on delivery (when the goods actually
     * changed hands), scoped by `paid_at` (when the sale itself
     * happened) — a delivered order pays and delivers close together in
     * practice, and this matches the COGS line's own recognition point.
     */
    private function salesRevenue(Carbon $from, Carbon $toExclusive): int
    {
        return (int) Order::query()
            ->where('is_test', false)
            ->where('delivery_status', DeliveryStatus::Delivered->value)
            ->whereBetween('paid_at', [$from, $toExclusive])
            ->sum('selling_price');
    }

    private function cogs(Carbon $from, Carbon $toExclusive): int
    {
        return (int) Order::query()
            ->where('is_test', false)
            ->where('delivery_status', DeliveryStatus::Delivered->value)
            ->whereBetween('paid_at', [$from, $toExclusive])
            ->sum('cost_price');
    }

    private function membershipRevenue(Carbon $from, Carbon $toExclusive): int
    {
        return (int) MembershipFeeRecord::query()
            ->whereBetween('created_at', [$from, $toExclusive])
            ->sum('amount_sen');
    }

    /**
     * ADR-083 decision 7 — "Σ transaction_fee charged − Σ CHIP Fee
     * actual", from every settlement batch whose window falls inside
     * this month (a batch is typically weekly, per this ADR's own
     * recommended cadence — several may fall inside one month).
     * `matched_fee_sen` (not the old `expected_fee_sen`, dropped by this
     * ADR's own same-day addendum) sums our own fee assumption ONLY for
     * the transactions each settlement actually matched — the same fix
     * that made `status` reliable also makes this line correct.
     */
    private function paymentProcessingGainLoss(Carbon $from, Carbon $toExclusive): int
    {
        $totals = PaymentSettlement::query()
            ->where('date_from', '>=', $from->toDateString())
            ->where('date_to', '<', $toExclusive->toDateString())
            ->selectRaw('COALESCE(SUM(matched_fee_sen), 0) as matched_fee, COALESCE(SUM(file_fee_sen), 0) as file_fee')
            ->first();

        return (int) $totals->matched_fee - (int) $totals->file_fee;
    }

    private function supplierPrepaidTopup(Carbon $from, Carbon $toExclusive): int
    {
        return (int) SupplierTransfer::query()
            ->whereNull('voided_at')
            ->whereBetween('created_at', [$from, $toExclusive])
            ->selectRaw('COALESCE(SUM(amount_myr_sent), 0) + COALESCE(SUM(fee_myr), 0) as total')
            ->value('total');
    }

    /**
     * ADR-083 decision 5 — "Σ orders.cost_price (delivered that month)
     * minus Σ (foreign drawn that month × weighted-average rate of that
     * supplier's transfers)". The weighted-average rate is computed
     * across that supplier's entire non-voided transfer history to
     * date (the "true blended cost of funds" framing decision 5's own
     * rationale uses), not just transfers inside this month.
     */
    private function supplierPrepaidFxVarianceTrueUp(Carbon $from, Carbon $toExclusive): int
    {
        $cogs = $this->cogs($from, $toExclusive);

        $drawdownsBySupplier = SupplierLedgerEntry::query()
            ->where('type', SupplierLedgerEntryType::OrderDrawdown->value)
            ->whereBetween('created_at', [$from, $toExclusive])
            ->selectRaw('supplier_id, SUM(ABS(amount)) as foreign_drawn')
            ->groupBy('supplier_id')
            ->get();

        $foreignDrawnMyrSen = 0;

        foreach ($drawdownsBySupplier as $row) {
            $rate = $this->weightedAverageRate((int) $row->supplier_id);

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
     * across every non-voided transfer for this supplier.
     */
    private function weightedAverageRate(int $supplierId): ?float
    {
        $transfers = SupplierTransfer::query()
            ->where('supplier_id', $supplierId)
            ->whereNull('voided_at')
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
            ->whereBetween('orders.paid_at', [$from, $toExclusive])
            ->sum('ledger_entries.amount');
    }

    private function voucherLiabilityIssued(Carbon $from, Carbon $toExclusive): int
    {
        return (int) Voucher::query()
            ->whereBetween('created_at', [$from, $toExclusive])
            ->sum('amount');
    }
}
