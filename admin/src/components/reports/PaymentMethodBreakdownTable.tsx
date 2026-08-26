"use client";

import type { ReportPaymentMethodRow } from "@/lib/reports";
import { formatRm } from "./format";

export function PaymentMethodBreakdownTable({ rows }: { rows: ReportPaymentMethodRow[] }) {
  if (rows.length === 0) {
    return <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No paid orders in this range yet.</p>;
  }

  return (
    <div className="max-w-full overflow-x-auto">
      <table className="w-full text-theme-sm">
        <thead>
          <tr className="border-b border-gray-200 text-left text-theme-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
            <th className="whitespace-nowrap px-4 py-3 font-medium">Payment Method</th>
            <th className="whitespace-nowrap px-4 py-3 font-medium">Sales</th>
            <th className="whitespace-nowrap px-4 py-3 font-medium">% of Sales</th>
            <th className="whitespace-nowrap px-4 py-3 font-medium">Orders</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.payment_method} className="border-b border-gray-100 last:border-0 dark:border-gray-800/60">
              <td className="whitespace-nowrap px-4 py-3 font-medium capitalize text-gray-800 dark:text-white/90">
                {row.payment_method}
              </td>
              <td className="whitespace-nowrap px-4 py-3 tabular-nums text-gray-800 dark:text-white/90">{formatRm(row.sales)}</td>
              <td className="whitespace-nowrap px-4 py-3 tabular-nums text-gray-500 dark:text-gray-400">{row.pct_of_sales.toFixed(1)}%</td>
              <td className="whitespace-nowrap px-4 py-3 tabular-nums text-gray-800 dark:text-white/90">{row.orders_count}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
