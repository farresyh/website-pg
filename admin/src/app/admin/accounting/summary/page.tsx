"use client";

/**
 * ADR-083 decision 8, fills ADR-110 PR-B — the Monthly Accounting Summary,
 * one period at a time: the journal lines Farres copies into the external
 * accounting SaaS (Bukku). Since the 2026-10-10 addendum it also carries
 * the month's operating profit and the month close (decisions 9–14).
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { Label } from "@/components/ui/label";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { InfoTooltip } from "@/components/dashboard/InfoTooltip";
import { type MonthClose, type MonthlyAccountingSummary, getMonthClose, getMonthlyAccountingSummary } from "@/lib/accounting-summary";
import { MonthClosePanel } from "@/components/accounting/MonthClosePanel";

function rm(sen: number): string {
  const negative = sen < 0;
  const formatted = `RM ${(Math.abs(sen) / 100).toFixed(2)}`;
  return negative ? `(${formatted})` : formatted;
}

/**
 * Added after the founder (this page's own target user) said the page
 * was unclear even to them — these lines deliberately mix real P&L
 * (revenue/COGS/expense) with capital-movement/liability lines
 * (supplier prepaid top-up, voucher liability, wallet balance), so each
 * needs its own plain-language explanation. Which lines make up operating
 * profit is decided in MonthlyAccountingSummaryService::operatingProfit().
 */
const LINES: { key: keyof MonthlyAccountingSummary; label: string; definition: string }[] = [
  { key: "gross_sales_sen", label: "Gross sales (before KOL promo)", definition: "What this month's recognised orders would have sold for without a KOL promo code. Information only: subtract the promo discount below to get the recognised revenue (ADR-118, the accountant's presentation)." },
  { key: "promo_discounts_sen", label: "Less: KOL promo code discounts", definition: "The discount customers got from KOL promo codes on orders recognised this month. It reduces revenue (contra-revenue, MPERS Section 23), not a marketing expense. For a partly delivered order, only the delivered share counts. Per-KOL figures are on the Campaigns page." },
  { key: "sales_revenue_sen", label: "Revenue (recognised)", definition: "Total selling price of every order delivered (recognised) this month, whenever it was paid — gross income, before subtracting what we paid suppliers. Not profit on its own. Money paid for an order not yet delivered is owed to the customer, not revenue (ADR-127)." },
  { key: "membership_revenue_sen", label: "Membership revenue", definition: "Total membership subscription fees collected this month." },
  { key: "cogs_sen", label: "COGS (at funded cost)", definition: "Cost of Goods Sold for this month's recognised orders, at what we actually paid for the supplier deposit: each order's drawdown × the MYR we paid per unit, transfer fees included (a moving average over the deposits, ADR-134). A pay-after supplier counts at face value. This is the accounting cost; it is subtracted from operating profit." },
  { key: "cogs_at_order_cost_sen", label: "↳ COGS at per-order cost", definition: "The same orders at the cost shown on each order (Transaction Register, Customer Detail): since ADR-134 the deposit's landed cost; older orders, the market rate on delivery day (their Cost Rate says which). Information only. This line plus the next one equals COGS." },
  { key: "cogs_funding_difference_sen", label: "↳ Order cost vs funded cost", definition: "COGS less the per-order cost above. For older orders (Cost Rate: market) it is how much more the deposit cost than the market rate they were costed at. For newer orders it is 0 unless a transfer was recorded late, corrected or voided after orders used it. Information only, already inside COGS." },
  { key: "drawdowns_not_in_cogs_sen", label: "Supplier drawdowns not in this month's COGS", definition: "Deposit used this month for orders whose sale is not recognised this month: a combo partly delivered and not yet settled, or an order paid in another month. Information only. It joins COGS in the month the sale is recognised. Normally 0." },
  { key: "payment_processing_gain_loss_sen", label: "Payment processing net gain/(loss)", definition: "The gap between the transaction fee charged to customers and the real fee CHIP actually deducted — a small gain if we charged more than CHIP's real cut, a loss if less." },
  { key: "supplier_prepaid_topup_sen", label: "Supplier prepaid — top-up", definition: "Capital sent this month to top up a supplier's prepaid balance (e.g. Digiflazz). Since October 2026 it includes our bank/transfer fee for that top-up, because the fee is part of the stock's cost and reaches COGS as the stock sells (ADR-134). A capital movement — not revenue, not an expense." },
  { key: "pay_after_supplier_payments_sen", label: "Supplier payments — pay-after", definition: "Paid this month to pay-after suppliers (a penjoki, a manual supplier) for orders they already delivered, excluding our own bank/transfer fee. It settles what we owe them; the cost itself is already in COGS when the order is delivered. Not revenue, not an expense, and not a prepaid balance." },
  { key: "bank_transfer_fees_sen", label: "Bank / transfer fees", definition: "Our own Wise/Airwallex/bank fee for sending money to a supplier, where it is a real expense: a pay-after supplier's payment, or a prepaid top-up sent before October 2026. A prepaid top-up's fee since then is in the top-up line above and in COGS instead (ADR-134). The lines above plus this one are the full amount that left the bank for suppliers." },
  { key: "affiliate_commission_expense_sen", label: "Affiliate commission expense", definition: "Commission owed to affiliates from this month's sales, whether or not it has actually been paid out yet — a real expense against profit." },
  { key: "affiliate_tier_fees_sen", label: "Affiliate tier fees (from earnings, no cash)", definition: "Wholesale-tier fees deducted from affiliates' earnings this month. No money moved, so the bank statement never shows it: it lowers what we owe affiliates and adds to profit. Whether it is revenue or a reduction of commission expense is the accountant's call." },
  { key: "voucher_breakage_sen", label: "Voucher breakage", definition: "What was left unspent on vouchers that expired this month. We no longer owe it, so it becomes income." },
  { key: "admin_issued_vouchers_sen", label: "Admin-issued vouchers (no order)", definition: "Vouchers an admin gave away this month that were not for a failed order. A real cost: the customer never paid us for them. It lowers operating profit, and the same credit is spendable by the customer, so do not add it to the line below. A Campaign promo code is a different thing, costed per order." },
  { key: "supplier_manual_adjustments_sen", label: "Supplier balance corrections", definition: "Manual corrections to a supplier's prepaid balance this month (e.g. a deposit fee missed at entry), in MYR at the month-end rate. Corrections on a voided transfer are left out." },
  { key: "reseller_rebates_sen", label: "Less: reseller rebates", definition: "Milestone rebates resellers earned this month (ADR-139). A reduction of revenue, not a marketing expense (consideration payable to a customer, MFRS 15), counted when the step is reached, claimed or not, and subtracted from operating profit. For the current month it is live: every step reached so far. A reward that lapses unclaimed is taken back, as a negative amount, in the month it lapses; the month it was earned in does not change." },
  { key: "reseller_rebate_breakage_sen", label: "Reseller rebate breakage", definition: "Rebate credit already in a reseller's Purchase Balance, unspent when the account was closed this month and forfeited under the programme terms (ADR-139). We no longer owe it, so it becomes income, like voucher breakage." },
  { key: "failed_order_credit_issued_sen", label: "Failed-order store credit issued", definition: "Store credit issued this month for a failed or partly delivered order. The customer already paid, so we owe it back: a liability, not an expense, and operating profit leaves it out. A voucher made by merging others is not new credit and is not counted. What customers can still spend overall is on the month close's cash equation." },
  { key: "reseller_wallet_balance_sen", label: "Reseller wallet balance", definition: "Money resellers have prepaid into their wallets, which isn't ours — a liability, not revenue. The balance at this month's end (today's balance for the current month)." },
  { key: "envelope_manual_expenses_sen", label: "Manual expenses (Envelope)", definition: "Expenses recorded in the Envelope Ledger this month, including ones a director paid, except KOL fee payments (the three lines below). Information only: they already came out of their envelopes, so operating profit does not subtract them again." },
  { key: "kol_fees_accrued_sen", label: "KOL fees — expense (accrued)", definition: "The advertising expense for KOL campaigns this month, from each campaign's fee schedule: the month the KOL worked, whenever we paid (ADR-118, the accountant's rule). The journal's advertising expense. Information only, like envelope expenses." },
  { key: "kol_fees_paid_sen", label: "KOL fees — paid (cash)", definition: "KOL fee payments recorded in the Envelope Ledger this month (tagged with their campaign). The cash side of the journal; the difference from the expense above moves the balance below." },
  { key: "kol_prepaid_balance_sen", label: "KOL fees — prepaid / (owed) at month end", definition: "All KOL payments to date less all fees accrued to date. Positive is a prepayment (paid ahead, an asset). In brackets is an accrued expense: fees the KOLs earned that we have not paid yet." },
];

export default function MonthlyAccountingSummaryPage() {
  const router = useRouter();
  const session = useClientSession();
  const now = new Date();
  const [year, setYear] = useState(now.getFullYear());
  const [month, setMonth] = useState(now.getMonth() + 1);
  const [summary, setSummary] = useState<MonthlyAccountingSummary | null>(null);
  const [monthClose, setMonthClose] = useState<MonthClose | null>(null);
  const [error, setError] = useState<string | null>(null);

  function load(y: number, m: number) {
    return Promise.all([getMonthlyAccountingSummary(y, m), getMonthClose(y, m)])
      .then(([s, c]) => {
        setError(null);
        setSummary(s);
        setMonthClose(c);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load the summary."));
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    load(year, month);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [year, month]);

  if (!session) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>;
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Monthly Accounting Summary</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Journal lines for one month — copy these into the external accounting SaaS — then close the month below.
        </p>
        <p className="mt-2 rounded-lg bg-brand-50 px-3 py-2 text-theme-sm text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
          The lines mix profit and loss with capital movements (supplier top-ups, wallet balances). Operating profit,
          at the bottom, adds up only the profit-and-loss lines; it is what the month close puts into the envelopes.
          For sales and profit by game or channel, see{" "}
          <Link href="/admin/reports" className="underline">
            Reports
          </Link>
          .
        </p>
      </div>

      <div className="mb-6 flex items-end gap-3">
        <div>
          <Label htmlFor="summary_year">Year</Label>
          <input
            id="summary_year"
            type="number"
            value={year}
            onChange={(e) => setYear(Number(e.target.value))}
            className="h-10 w-24 rounded-lg border border-gray-200 px-3 text-theme-sm dark:border-gray-800 dark:bg-white/[0.03] dark:text-white/90"
          />
        </div>
        <div>
          <Label htmlFor="summary_month">Month</Label>
          <select
            id="summary_month"
            value={month}
            onChange={(e) => setMonth(Number(e.target.value))}
            className="h-10 rounded-lg border border-gray-200 px-3 text-theme-sm dark:border-gray-800 dark:bg-white/[0.03] dark:text-white/90"
          >
            {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => (
              <option key={m} value={m}>
                {new Date(2000, m - 1, 1).toLocaleString("en-US", { month: "long" })}
              </option>
            ))}
          </select>
        </div>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      {summary && (
        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
          <table className="w-full">
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {LINES.map((line) => (
                <tr key={line.key}>
                  <td className="px-5 py-4 text-theme-sm text-gray-600 dark:text-gray-300">
                    <span className="inline-flex items-center gap-1">
                      {line.label}
                      <InfoTooltip definition={line.definition} />
                      {line.key === "cogs_sen" && summary.cogs_fallback_order_count > 0 && (
                        <span className="text-theme-xs text-warning-600 dark:text-warning-400">
                          ({summary.cogs_fallback_order_count} order{summary.cogs_fallback_order_count === 1 ? "" : "s"} at their own cost: no deposit drawdown or rate recorded)
                        </span>
                      )}
                    </span>
                  </td>
                  <td className="px-5 py-4 text-right font-mono text-theme-sm text-gray-800 dark:text-white/90">
                    {rm(summary[line.key])}
                  </td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr className="border-t-2 border-gray-200 dark:border-gray-700">
                <td className="px-5 py-4 text-theme-sm font-semibold text-gray-800 dark:text-white/90">
                  <span className="inline-flex items-center gap-1">
                    Operating profit
                    <InfoTooltip definition="Sales and membership revenue, less COGS at funded cost, payment processing, bank fees and affiliate commission, plus tier fees and voucher breakage, less admin-issued vouchers, plus supplier corrections. Failed-order store credit and envelope expenses are deliberately left out." />
                  </span>
                </td>
                <td className="px-5 py-4 text-right font-mono text-theme-sm font-semibold text-gray-800 dark:text-white/90">
                  {rm(summary.operating_profit_sen)}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      )}

      {monthClose && session && (
        <MonthClosePanel key={monthClose.period} year={year} month={month} data={monthClose} onChanged={() => load(year, month)} />
      )}
    </div>
  );
}
