"use client";

/**
 * ADR-083 decision 8, fills ADR-110 PR-B — read-only Monthly Accounting
 * Summary. One period at a time; the journal lines Farres copies into
 * the external accounting SaaS (Bukku) each month close.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Label } from "@/components/ui/label";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { type MonthlyAccountingSummary, getMonthlyAccountingSummary } from "@/lib/accounting-summary";

function rm(sen: number): string {
  const negative = sen < 0;
  const formatted = `RM ${(Math.abs(sen) / 100).toFixed(2)}`;
  return negative ? `(${formatted})` : formatted;
}

const LINES: { key: keyof MonthlyAccountingSummary; label: string }[] = [
  { key: "sales_revenue_sen", label: "Sales revenue" },
  { key: "membership_revenue_sen", label: "Membership revenue" },
  { key: "cogs_sen", label: "COGS" },
  { key: "payment_processing_gain_loss_sen", label: "Payment processing net gain/(loss)" },
  { key: "supplier_prepaid_topup_sen", label: "Supplier prepaid — top-up" },
  { key: "supplier_prepaid_fx_variance_sen", label: "Supplier prepaid — FX variance true-up" },
  { key: "affiliate_commission_expense_sen", label: "Affiliate commission expense" },
  { key: "voucher_liability_issued_sen", label: "Voucher liability issued" },
];

export default function MonthlyAccountingSummaryPage() {
  const router = useRouter();
  const session = useClientSession();
  const now = new Date();
  const [year, setYear] = useState(now.getFullYear());
  const [month, setMonth] = useState(now.getMonth() + 1);
  const [summary, setSummary] = useState<MonthlyAccountingSummary | null>(null);
  const [error, setError] = useState<string | null>(null);

  function load(token: string, y: number, m: number) {
    getMonthlyAccountingSummary(token, y, m)
      .then(setSummary)
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
                  <td className="px-5 py-4 text-theme-sm text-gray-600 dark:text-gray-300">{line.label}</td>
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
