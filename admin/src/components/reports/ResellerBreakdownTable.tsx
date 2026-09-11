"use client";

import type { ReportResellerRow } from "@/lib/reports";
import { formatRm } from "./format";

/** ADR-086 PR-2 — mirrors AffiliateBreakdownTable, no Affiliate Profit column (wallet orders never revenue-share). */
export function ResellerBreakdownTable({ rows }: { rows: ReportResellerRow[] }) {
  if (rows.length === 0) {
    return <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No wallet-reseller orders in this range yet.</p>;
  }

  return (
    <div className="max-w-full overflow-x-auto">
      <table className="w-full text-theme-sm">
        <thead>
          <tr className="border-b border-gray-200 text-left text-theme-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
            <th className="whitespace-nowrap px-4 py-3 font-medium">Reseller</th>
            <th className="whitespace-nowrap px-4 py-3 font-medium">Sales</th>
            <th className="whitespace-nowrap px-4 py-3 font-medium">Orders</th>
            <th className="whitespace-nowrap px-4 py-3 font-medium">Owner Profit</th>
            <th className="whitespace-nowrap px-4 py-3 font-medium">Avg Order</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.reseller_id} className="border-b border-gray-100 last:border-0 dark:border-gray-800/60">
              <td className="whitespace-nowrap px-4 py-3 font-medium text-gray-800 dark:text-white/90">{row.reseller_name}</td>
              <td className="whitespace-nowrap px-4 py-3 tabular-nums text-gray-800 dark:text-white/90">{formatRm(row.sales)}</td>
              <td className="whitespace-nowrap px-4 py-3 tabular-nums text-gray-800 dark:text-white/90">{row.orders_count}</td>
              <td className="whitespace-nowrap px-4 py-3 tabular-nums text-success-600 dark:text-success-400">
                {formatRm(row.platform_profit)}
              </td>
              <td className="whitespace-nowrap px-4 py-3 tabular-nums text-gray-500 dark:text-gray-400">
                {formatRm(row.avg_order_value)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
