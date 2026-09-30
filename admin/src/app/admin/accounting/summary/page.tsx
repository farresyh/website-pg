"use client";

/**
 * ADR-083 decision 8, fills ADR-110 PR-B — read-only Monthly Accounting
 * Summary. One period at a time; the journal lines Farres copies into
 * the external accounting SaaS (Bukku) each month close.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { Label } from "@/components/ui/label";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { InfoTooltip } from "@/components/dashboard/InfoTooltip";
import { type MonthlyAccountingSummary, getMonthlyAccountingSummary } from "@/lib/accounting-summary";

function rm(sen: number): string {
  const negative = sen < 0;
  const formatted = `RM ${(Math.abs(sen) / 100).toFixed(2)}`;
  return negative ? `(${formatted})` : formatted;
}

/**
 * Added after the founder (this page's own target user) said the page
 * was unclear even to them — these 8 lines deliberately mix real P&L
 * (revenue/COGS/expense) with capital-movement/liability lines
 * (supplier prepaid top-up/FX variance, voucher liability), which is
 * exactly why there's no single "profit" figure on this page (see the
 * note below the table) — each line needs its own plain-language
 * explanation to be read correctly on its own.
 */
const LINES: { key: keyof MonthlyAccountingSummary; label: string; definition: string }[] = [
  { key: "sales_revenue_sen", label: "Sales revenue", definition: "Total selling price of every order delivered this month — gross income, before subtracting what we paid suppliers. Not profit on its own." },
  { key: "membership_revenue_sen", label: "Membership revenue", definition: "Total membership subscription fees collected this month." },
  { key: "cogs_sen", label: "COGS", definition: "Cost of Goods Sold — the total we paid suppliers (e.g. Digiflazz) for those same delivered orders. Sales revenue minus this is roughly retail margin, still not the final profit figure." },
  { key: "payment_processing_gain_loss_sen", label: "Payment processing net gain/(loss)", definition: "The gap between the transaction fee charged to customers and the real fee CHIP actually deducted — a small gain if we charged more than CHIP's real cut, a loss if less." },
  { key: "supplier_prepaid_topup_sen", label: "Supplier prepaid — top-up", definition: "Capital sent this month to top up a supplier's prepaid balance (e.g. Digiflazz), excluding our own bank/transfer fee (see the line below). A capital movement — not revenue, not an expense." },
  { key: "bank_transfer_fees_sen", label: "Bank / transfer fees", definition: "Our own Wise/Airwallex fee for sending the top-up above to the supplier — a real expense, separate from the capital movement itself. Add this to the top-up line above to get the full amount that left the bank." },
  { key: "supplier_prepaid_fx_variance_sen", label: "Supplier prepaid — FX variance true-up", definition: "The gap between the FX rate assumed when pricing packages and the real blended rate actually paid to suppliers this month — tells you whether the pricing buffer is set correctly, not a cash gain or loss." },
  { key: "affiliate_commission_expense_sen", label: "Affiliate commission expense", definition: "Commission owed to affiliates from this month's sales, whether or not it has actually been paid out yet — a real expense against profit." },
  { key: "voucher_liability_issued_sen", label: "Voucher liability issued", definition: "Value of store-credit vouchers issued this month to customers whose orders failed — a liability (credit we owe back), not a cash expense." },
  { key: "reseller_wallet_balance_sen", label: "Reseller wallet balance", definition: "Money resellers have prepaid into their wallets, which isn't ours — a liability, not revenue. Always the CURRENT balance (see the \"as of\" timestamp next to it), never this period's own month-end figure — no historical snapshot exists." },
];

export default function MonthlyAccountingSummaryPage() {
  const router = useRouter();
  const session = useClientSession();
  const now = new Date();
  const [year, setYear] = useState(now.getFullYear());
  const [month, setMonth] = useState(now.getMonth() + 1);
  const [summary, setSummary] = useState<MonthlyAccountingSummary | null>(null);
  const [error, setError] = useState<string | null>(null);
  // reseller_wallet_balance_sen is always the CURRENT balance, never scoped
  // to the viewed period — this timestamps exactly when that snapshot was
  // taken, so it's never mistaken for a real month-end figure.
  const [fetchedAt, setFetchedAt] = useState<Date | null>(null);

  function load(token: string, y: number, m: number) {
    getMonthlyAccountingSummary(token, y, m)
      .then((s) => {
        setSummary(s);
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
          Read-only journal lines for one closed period — copy these into the external accounting SaaS each month.
        </p>
        <p className="mt-2 rounded-lg bg-brand-50 px-3 py-2 text-theme-sm text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
          This is a bookkeeping export, not a profit statement — these lines deliberately mix revenue/expenses with
          capital movements (supplier top-ups, FX variance), so there is no single &quot;profit&quot; figure below.
          For sales and profit, see{" "}
          <Link href="/admin/reports" className="underline">
            Reports
          </Link>
          {" "}→ Profit Analysis tab.
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
          </table>
        </div>
      )}
    </div>
  );
}
