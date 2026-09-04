"use client";

import { useEffect, useState } from "react";
import { type ReportFilters, type ReportOrderStatusFunnel, getOrderStatusFunnel } from "@/lib/reports";
import { OrderStatusFunnelChart } from "../OrderStatusFunnelChart";

export function OrdersTab({ token, filters }: { token: string; filters: ReportFilters }) {
  const [funnel, setFunnel] = useState<ReportOrderStatusFunnel | null>(null);

  useEffect(() => {
    getOrderStatusFunnel(token, filters)
      .then(setFunnel)
      .catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, filters.year, filters.month, filters.affiliateId]);

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-2">
        <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Delivery Status Funnel</h2>
        <p className="mb-4 text-theme-xs text-gray-400 dark:text-gray-500">
          Among paid orders only — a full actionable worklist lives at Orders in the sidebar, this is a health snapshot.
        </p>
        {funnel ? <OrderStatusFunnelChart funnel={funnel} /> : <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
      </div>

      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Summary</h2>
        <div className="space-y-4">
          <div>
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">Total Paid Orders</p>
            <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{funnel ? funnel.total.toLocaleString() : "—"}</p>
          </div>
          <div>
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">Successful Delivery Rate</p>
            <p className="mt-1 text-lg font-semibold text-success-600 dark:text-success-400">
              {funnel ? `${funnel.success_rate_pct.toFixed(1)}%` : "—"}
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
