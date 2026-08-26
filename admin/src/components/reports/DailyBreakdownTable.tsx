"use client";

import type { ReportDailyBreakdownRow } from "@/lib/reports";
import { formatDate, formatRm } from "./format";

export function DailyBreakdownTable({ rows }: { rows: ReportDailyBreakdownRow[] }) {
  if (rows.length === 0) {
    return <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No paid orders in this range yet.</p>;
  }

  return (
    <div className="max-w-full overflow-x-auto">
      <table className="w-full text-theme-sm">
        <thead>
          <tr className="border-b border-gray-200 text-left text-theme-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
            <th className="whitespace-nowrap px-4 py-3 font-medium">Date</th>
            <th className="whitespace-nowrap px-4 py-3 font-medium">Orders</th>
            <th className="whitespace-nowrap px-4 py-3 font-medium">Sales</th>
            <th className="whitespace-nowrap px-4 py-3 font-medium">Owner Profit</th>
            <th className="whitespace-nowrap px-4 py-3 font-medium">Reseller Profit</th>
            <th className="whitespace-nowrap px-4 py-3 font-medium">Fees</th>
            <th className="whitespace-nowrap px-4 py-3 font-medium">Avg Order</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.date} className="border-b border-gray-100 last:border-0 dark:border-gray-800/60">
              <td className="whitespace-nowrap px-4 py-3 text-gray-600 dark:text-gray-300">{formatDate(row.date)}</td>
              <td className="whitespace-nowrap px-4 py-3 tabular-nums text-gray-800 dark:text-white/90">{row.orders_count}</td>
              <td className="whitespace-nowrap px-4 py-3 tabular-nums text-gray-800 dark:text-white/90">{formatRm(row.sales)}</td>
              <td className="whitespace-nowrap px-4 py-3 tabular-nums text-success-600 dark:text-success-400">
                {formatRm(row.platform_profit)}
              </td>
              <td className="whitespace-nowrap px-4 py-3 tabular-nums text-violet-600 dark:text-violet-400">
                {formatRm(row.reseller_profit)}
              </td>
              <td className="whitespace-nowrap px-4 py-3 tabular-nums text-gray-500 dark:text-gray-400">
                {formatRm(row.transaction_fees)}
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
