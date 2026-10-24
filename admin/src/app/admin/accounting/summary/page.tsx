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
  { key: "sales_revenue_sen", label: "Revenue (recognised)", definition: "Total selling price of every order delivered (recognised) this month, whenever it was paid — gross income, before subtracting what we paid suppliers. Not profit on its own. Money paid for an order not yet delivered is owed to the customer, not revenue (ADR-127)." },
  { key: "membership_revenue_sen", label: "Membership revenue", definition: "Total membership subscription fees collected this month." },
  { key: "cogs_sen", label: "COGS (at funded cost)", definition: "Cost of Goods Sold for this month's recognised orders, at what we actually paid for the supplier deposit: each order's drawdown from the deposit × the MYR we paid per unit (blended over every top-up up to the month's last day). A supplier paid in MYR counts at face value. This is the accounting cost; it is subtracted from operating profit." },
  { key: "cogs_at_order_cost_sen", label: "↳ COGS at per-order cost", definition: "The same orders at the cost shown on each order (Transaction Register, Customer Detail): the supplier's price at the market rate on the day it was delivered. Information only. This line plus the next one equals COGS." },
  { key: "cogs_funding_difference_sen", label: "↳ Deposit rate vs market rate", definition: "COGS less the per-order cost above: how much more (positive) or less (negative) the deposit's rate cost us than the market rate on delivery day. Information only, already inside COGS. A large figure says whether the pricing FX buffer is set right. Not all of it is FX: supplier price moves and rounding are in it too." },
  { key: "drawdowns_not_in_cogs_sen", label: "Supplier drawdowns not in this month's COGS", definition: "Deposit used this month for orders whose sale is not recognised this month: a combo partly delivered and not yet settled, or an order paid in another month. Information only. It joins COGS in the month the sale is recognised. Normally 0." },
  { key: "payment_processing_gain_loss_sen", label: "Payment processing net gain/(loss)", definition: "The gap between the transaction fee charged to customers and the real fee CHIP actually deducted — a small gain if we charged more than CHIP's real cut, a loss if less." },
  { key: "supplier_prepaid_topup_sen", label: "Supplier prepaid — top-up", definition: "Capital sent this month to top up a supplier's prepaid balance (e.g. Digiflazz), excluding our own bank/transfer fee (see the line below). A capital movement — not revenue, not an expense." },
  { key: "bank_transfer_fees_sen", label: "Bank / transfer fees", definition: "Our own Wise/Airwallex fee for sending the top-up above to the supplier — a real expense, separate from the capital movement itself. Add this to the top-up line above to get the full amount that left the bank." },
  { key: "affiliate_commission_expense_sen", label: "Affiliate commission expense", definition: "Commission owed to affiliates from this month's sales, whether or not it has actually been paid out yet — a real expense against profit." },
  { key: "affiliate_tier_fees_sen", label: "Affiliate tier fees (from earnings, no cash)", definition: "Wholesale-tier fees deducted from affiliates' earnings this month. No money moved, so the bank statement never shows it: it lowers what we owe affiliates and adds to profit. Whether it is revenue or a reduction of commission expense is the accountant's call." },
  { key: "voucher_breakage_sen", label: "Voucher breakage", definition: "What was left unspent on vouchers that expired this month. We no longer owe it, so it becomes income." },
  { key: "admin_issued_vouchers_sen", label: "Admin-issued vouchers (no order)", definition: "Vouchers an admin gave away this month that were not for a failed order. A real cost: the customer never paid us for them. It lowers operating profit, and the same credit is spendable by the customer, so do not add it to the line below. A Campaign promo code is a different thing, costed per order." },
  { key: "supplier_manual_adjustments_sen", label: "Supplier balance corrections", definition: "Manual corrections to a supplier's prepaid balance this month (e.g. a deposit fee missed at entry), in MYR at the month-end rate. Corrections on a voided transfer are left out." },
  { key: "failed_order_credit_issued_sen", label: "Failed-order store credit issued", definition: "Store credit issued this month for a failed or partly delivered order. The customer already paid, so we owe it back: a liability, not an expense, and operating profit leaves it out. A voucher made by merging others is not new credit and is not counted. What customers can still spend overall is on the month close's cash equation." },
  { key: "reseller_wallet_balance_sen", label: "Reseller wallet balance", definition: "Money resellers have prepaid into their wallets, which isn't ours — a liability, not revenue. The balance at this month's end (today's balance for the current month)." },
  { key: "envelope_manual_expenses_sen", label: "Manual expenses (Envelope)", definition: "Expenses recorded in the Envelope Ledger this month, including ones a director paid. Information only: they already came out of their envelopes, so operating profit does not subtract them again." },
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
