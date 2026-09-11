"use client";

import { useEffect, useState } from "react";
import { type ReportFilters, type ReportPaymentMethodRow, type ReportGameRow, getPaymentMethodBreakdown, getGameBreakdown } from "@/lib/reports";
import { HorizontalBarList } from "../HorizontalBarList";
import { PaymentMethodBreakdownTable } from "../PaymentMethodBreakdownTable";
import { toRm } from "../format";

export function SalesAnalysisTab({ token, filters }: { token: string; filters: ReportFilters }) {
  const [paymentMethods, setPaymentMethods] = useState<ReportPaymentMethodRow[] | null>(null);
  const [games, setGames] = useState<ReportGameRow[] | null>(null);

  useEffect(() => {
    getPaymentMethodBreakdown(token, filters)
      .then((res) => setPaymentMethods(res.payment_methods))
      .catch(() => undefined);
    getGameBreakdown(token, filters)
      .then((res) => setGames(res.games))
      .catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, filters.from, filters.to, filters.affiliateId]);

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Sales by Payment Method</h2>
        {paymentMethods ? (
          <HorizontalBarList
            items={paymentMethods.map((p) => ({ label: p.payment_method, value: toRm(p.sales), sublabel: `${p.pct_of_sales.toFixed(1)}% of sales · ${p.orders_count} orders` }))}
            formatValue={(v) => `RM ${v.toLocaleString("en-MY", { maximumFractionDigits: 0 })}`}
          />
        ) : (
          <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
        )}
      </div>

      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Sales by Game</h2>
        {games ? (
          <HorizontalBarList
            items={games.map((g) => ({ label: g.game_name, value: toRm(g.sales), sublabel: `${g.pct_of_sales.toFixed(1)}% of sales` }))}
            formatValue={(v) => `RM ${v.toLocaleString("en-MY", { maximumFractionDigits: 0 })}`}
          />
        ) : (
          <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
        )}
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-2">
        <h2 className="px-4 pt-4 text-sm font-semibold text-gray-800 dark:text-white/90">Payment Method Detail</h2>
        {paymentMethods ? (
          <PaymentMethodBreakdownTable rows={paymentMethods} />
        ) : (
          <p className="p-6 text-sm text-gray-500 dark:text-gray-400">Loading…</p>
        )}
      </div>
    </div>
  );
}
