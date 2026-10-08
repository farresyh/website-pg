"use client";

/**
 * ADR-104 R7 — walks Paid sales (money collected) to the Monthly
 * Summary's recognised revenue. Every line comes from the backend's one
 * `RecognisedRevenue` seam; nothing is computed here. "Unexplained
 * difference" must be 0 and is always shown, never hidden. The backend
 * returns null under an Affiliate filter (the Monthly Summary is
 * company-wide), and the bridge is then not rendered.
 */

import Link from "next/link";
import { ChevronDown } from "@primeicons/react/chevron-down";
import type { ReportAccountingBridge } from "@/lib/reports";
import { formatRm } from "./format";

export function AccountingBridge({ bridge }: { bridge: ReportAccountingBridge }) {
  const lines: { sign: "" | "−" | "+" | "="; label: string; note?: string; sen: number; strong?: boolean }[] = [
    { sign: "", label: "Paid sales", note: "Money collected on paid orders", sen: bridge.paid_sales, strong: true },
    { sign: "−", label: "Customer-paid transaction fees", note: "Accounting books these under payment processing", sen: bridge.transaction_fees },
    { sign: "+", label: "Checkout voucher discounts", note: "Accounting counts the price before the discount", sen: bridge.voucher_discounts },
    { sign: "−", label: "Orders not recognised", note: "Failed, still in flight, or an unsettled partial delivery", sen: bridge.not_recognised },
    { sign: "−", label: "Compensation on settled partial orders", sen: bridge.partial_compensation },
    { sign: "=", label: "Recognised revenue", note: "Matches Accounting › Monthly Summary", sen: bridge.recognised_revenue, strong: true },
  ];
  const unexplained = bridge.unexplained_difference !== 0;

  return (
    <details className="group mb-6 overflow-hidden rounded-lg border border-border bg-surface shadow-xs">
      <summary className="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-3.5 focus-visible:outline-2 focus-visible:outline-focus-ring [&::-webkit-details-marker]:hidden">
        <span>
          <span className="text-section-title font-semibold text-ink">Bridge to Accounting</span>
          <span className="ml-2 text-theme-xs text-ink-muted">
            How Paid sales {formatRm(bridge.paid_sales)} becomes recognised revenue {formatRm(bridge.recognised_revenue)}
          </span>
        </span>
        <ChevronDown width={14} height={14} className="shrink-0 text-ink-muted transition-transform group-open:rotate-180" aria-hidden />
      </summary>
      <div className="border-t border-border px-5 py-4">
        <table className="w-full text-theme-sm">
          <tbody>
            {lines.map((line) => (
              <tr key={line.label} className={line.sign === "=" ? "border-t border-border-strong" : ""}>
                <td className="w-6 py-2 align-top font-semibold text-ink-muted">{line.sign}</td>
                <td className="py-2 pr-4">
                  <span className={line.strong ? "font-semibold text-ink" : "text-ink"}>{line.label}</span>
                  {line.note && <span className="block text-theme-xs text-ink-muted">{line.note}</span>}
                </td>
                <td className={`whitespace-nowrap py-2 text-right align-top tabular-nums ${line.strong ? "font-semibold text-ink" : "text-ink"}`}>{formatRm(line.sen)}</td>
              </tr>
            ))}
            <tr>
              <td />
              <td className="py-2 pr-4">
                <span className={unexplained ? "font-semibold text-danger-ink" : "text-ink-muted"}>Unexplained difference</span>
                <span className="block text-theme-xs text-ink-muted">Should be RM 0.00. Anything else means the two definitions have drifted.</span>
              </td>
              <td className={`whitespace-nowrap py-2 text-right align-top tabular-nums ${unexplained ? "font-semibold text-danger-ink" : "text-ink-muted"}`}>
                {formatRm(bridge.unexplained_difference)}
              </td>
            </tr>
          </tbody>
        </table>
        <p className="mt-3 text-theme-xs text-ink-muted">
          Company-wide, same date range. See{" "}
          <Link href="/admin/accounting/summary" className="font-medium text-cyan-ink hover:underline">
            Monthly Summary
          </Link>
          .
        </p>
      </div>
    </details>
  );
}
