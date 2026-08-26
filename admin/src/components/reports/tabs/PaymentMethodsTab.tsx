"use client";

import { useEffect, useState } from "react";
import { type ReportFilters, type ReportPaymentMethodRow, getPaymentMethodBreakdown } from "@/lib/reports";
import { HorizontalBarList } from "../HorizontalBarList";
import { PaymentMethodBreakdownTable } from "../PaymentMethodBreakdownTable";
import { toRm } from "../format";

export function PaymentMethodsTab({ token, filters }: { token: string; filters: ReportFilters }) {
  const [rows, setRows] = useState<ReportPaymentMethodRow[] | null>(null);

  useEffect(() => {
    getPaymentMethodBreakdown(token, filters)
      .then((res) => setRows(res.payment_methods))
      .catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, filters.year, filters.month, filters.resellerId]);

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Sales by Payment Method</h2>
        {rows ? (
          <HorizontalBarList
            items={rows.map((r) => ({ label: r.payment_method, value: toRm(r.sales), sublabel: `${r.orders_count} orders` }))}
            formatValue={(v) => `RM ${v.toLocaleString("en-MY", { maximumFractionDigits: 0 })}`}
          />
        ) : (
          <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
        )}
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 className="px-4 pt-4 text-sm font-semibold text-gray-800 dark:text-white/90">Detail</h2>
        {rows ? <PaymentMethodBreakdownTable rows={rows} /> : <p className="p-6 text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
      </div>
    </div>
  );
}
