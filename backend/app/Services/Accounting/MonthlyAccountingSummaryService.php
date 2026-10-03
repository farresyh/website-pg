<?php

namespace App\Services\Accounting;

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
use Illuminate\Support\Collection;

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
        // Add the month in KL time, then convert: adding it after the UTC
        // shift ended the period one KL day early whenever the previous
        // month is shorter (31 Oct KL fell into no month at all).
        $monthStart = Carbon::create($year, $month, 1, 0, 0, 0, ReportService::TIMEZONE);
        $from = $monthStart->copy()->setTimezone('UTC');
        $toExclusive = $monthStart->copy()->addMonthNoOverflow()->setTimezone('UTC');

        return [
            'sales_revenue_sen' => $this->salesRevenue($from, $toExclusive),
            'membership_revenue_sen' => $this->membershipRevenue($from, $toExclusive),
            'cogs_sen' => $this->cogs($from, $toExclusive),
            'payment_processing_gain_loss_sen' => $this->paymentProcessingGainLoss($from, $toExclusive),
            'supplier_prepaid_topup_sen' => $this->supplierPrepaidTopup($from, $toExclusive),
            'bank_transfer_fees_sen' => $this->bankTransferFees($from, $toExclusive),
            'supplier_prepaid_fx_variance_sen' => $this->supplierPrepaidFxVarianceTrueUp($from, $toExclusive),
            'affiliate_commission_expense_sen' => $this->affiliateCommissionExpense($from, $toExclusive),
            'voucher_liability_issued_sen' => $this->voucherLiabilityIssued($from, $toExclusive),
            'reseller_wallet_balance_sen' => $this->resellerWalletBalance(),
        ];
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
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $toExclusive)
            ->sum('selling_price')
            + (int) $this->settledPartialOrders($from, $toExclusive)->sum(fn (Order $o) => $o->selling_price - $o->compensationAmountSen());
    }

    private function cogs(Carbon $from, Carbon $toExclusive): int
    {
        return (int) Order::query()
            ->where('is_test', false)
            ->where('delivery_status', DeliveryStatus::Delivered->value)
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $toExclusive)
            ->sum('cost_price')
            + (int) $this->settledPartialOrders($from, $toExclusive)->sum(fn (Order $o) => $o->effectiveCostPriceSen());
    }

    /**
     * ADR-094 decision 41 — a partially delivered order is recognised
     * once settled (like a Failed one, it has no final economics before):
     * revenue is what it kept after compensation, COGS its delivered legs
     * only (the same figures its credited profit used). Few rows, so
     * loaded rather than expressed in SQL.
     *
     * @return Collection<int, Order>
     */
    private function settledPartialOrders(Carbon $from, Carbon $toExclusive): Collection
    {
        return Order::query()
            ->where('is_test', false)
            ->where('delivery_status', DeliveryStatus::PartiallyDelivered->value)
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $toExclusive)
            ->with(['voucher', 'voucherRedemption', 'deliveryLegs.componentPackage:id,cost_price'])
            ->get()
            ->filter(fn (Order $o) => $o->isAlreadyCompensated());
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
            // Settlement windows are KL calendar dates, so compare against
            // the KL dates, not the UTC instants' dates.
            ->where('date_from', '>=', $from->copy()->setTimezone(ReportService::TIMEZONE)->toDateString())
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
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
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
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->sum('fee_myr');
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
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
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
            ->where('orders.paid_at', '>=', $from)
            ->where('orders.paid_at', '<', $toExclusive)
            ->sum('ledger_entries.amount');
    }

    private function voucherLiabilityIssued(Carbon $from, Carbon $toExclusive): int
    {
        return (int) Voucher::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->sum('amount');
    }
}
