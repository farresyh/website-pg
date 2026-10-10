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
  { key: "sales_revenue_sen", label: "Sales revenue", definition: "Total selling price of every order delivered this month — gross income, before subtracting what we paid suppliers. Not profit on its own." },
  { key: "membership_revenue_sen", label: "Membership revenue", definition: "Total membership subscription fees collected this month." },
  { key: "cogs_sen", label: "COGS", definition: "Cost of Goods Sold — the total we paid suppliers (e.g. Digiflazz) for those same delivered orders. Sales revenue minus this is roughly retail margin, still not the final profit figure." },
  { key: "payment_processing_gain_loss_sen", label: "Payment processing net gain/(loss)", definition: "The gap between the transaction fee charged to customers and the real fee CHIP actually deducted — a small gain if we charged more than CHIP's real cut, a loss if less." },
  { key: "supplier_prepaid_topup_sen", label: "Supplier prepaid — top-up", definition: "Capital sent this month to top up a supplier's prepaid balance (e.g. Digiflazz), excluding our own bank/transfer fee (see the line below). A capital movement — not revenue, not an expense." },
  { key: "bank_transfer_fees_sen", label: "Bank / transfer fees", definition: "Our own Wise/Airwallex fee for sending the top-up above to the supplier — a real expense, separate from the capital movement itself. Add this to the top-up line above to get the full amount that left the bank." },
  { key: "supplier_prepaid_fx_variance_sen", label: "Supplier prepaid — FX variance true-up", definition: "COGS less what this month's supplier drawdowns really cost at the blended rate we paid for the supplier balance (as of the month's last day). Added to operating profit, so profit uses the real cost. A large figure also tells you whether the pricing FX buffer is set correctly." },
  { key: "affiliate_commission_expense_sen", label: "Affiliate commission expense", definition: "Commission owed to affiliates from this month's sales, whether or not it has actually been paid out yet — a real expense against profit." },
  { key: "affiliate_tier_fees_sen", label: "Affiliate tier fees (from earnings, no cash)", definition: "Wholesale-tier fees deducted from affiliates' earnings this month. No money moved, so the bank statement never shows it: it lowers what we owe affiliates and adds to profit. Whether it is revenue or a reduction of commission expense is the accountant's call." },
  { key: "voucher_breakage_sen", label: "Voucher breakage", definition: "What was left unspent on vouchers that expired this month. We no longer owe it, so it becomes income." },
  { key: "goodwill_vouchers_issued_sen", label: "Goodwill vouchers issued", definition: "Vouchers given away this month that were not for a failed order (a gesture, a promotion). A real cost: the customer never paid us for them." },
  { key: "supplier_manual_adjustments_sen", label: "Supplier balance corrections", definition: "Manual corrections to a supplier's prepaid balance this month (e.g. a deposit fee missed at entry), in MYR at the month-end rate. Corrections on a voided transfer are left out." },
  { key: "voucher_liability_issued_sen", label: "Voucher liability issued", definition: "Value of every store-credit voucher issued this month. Vouchers for failed orders are credit we owe back, a liability, not an expense, so operating profit leaves them out; goodwill vouchers are counted above." },
  { key: "reseller_wallet_balance_sen", label: "Reseller wallet balance", definition: "Money resellers have prepaid into their wallets, which isn't ours — a liability, not revenue. Always the CURRENT balance (see the \"as of\" timestamp next to it), never this period's own month-end figure. The month close's equation has the month-end figure." },
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
  // reseller_wallet_balance_sen is always the CURRENT balance, never scoped
  // to the viewed period — this timestamps exactly when that snapshot was
  // taken, so it's never mistaken for a real month-end figure.
  const [fetchedAt, setFetchedAt] = useState<Date | null>(null);

  function load(token: string, y: number, m: number) {
    return Promise.all([getMonthlyAccountingSummary(token, y, m), getMonthClose(token, y, m)])
      .then(([s, c]) => {
        setError(null);
        setSummary(s);
        setMonthClose(c);
        setFetchedAt(new Date());
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load the summary."));
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    load(s.token, year, month);
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
                      {line.key === "reseller_wallet_balance_sen" && fetchedAt && (
                        <span className="text-theme-xs text-gray-400 dark:text-gray-500">
                          (as of {fetchedAt.toLocaleString("en-MY", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })})
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
                    <InfoTooltip definition="Sales and membership revenue, less the real supplier cost (COGS with its FX true-up), payment processing, bank fees and affiliate commission, plus tier fees and voucher breakage, less goodwill vouchers, plus supplier corrections. Compensation vouchers and envelope expenses are deliberately left out." />
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
        <MonthClosePanel key={monthClose.period} token={session.token} year={year} month={month} data={monthClose} onChanged={() => load(session.token, year, month)} />
      )}
    </div>
  );
}
