"use client";

import { useEffect, useState } from "react";
import { type ReportFilters, type ReportResellerRow, getResellerBreakdown } from "@/lib/reports";
import { ResellerBreakdownTable } from "../ResellerBreakdownTable";

export function ResellersTab({ token, filters }: { token: string; filters: ReportFilters }) {
  const [rows, setRows] = useState<ReportResellerRow[] | null>(null);

  useEffect(() => {
    getResellerBreakdown(token, filters)
      .then((res) => setRows(res.resellers))
      .catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, filters.year, filters.month, filters.resellerId]);

  return (
    <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
      <h2 className="px-4 pt-4 text-sm font-semibold text-gray-800 dark:text-white/90">Resellers Breakdown</h2>
      {rows ? <ResellerBreakdownTable rows={rows} /> : <p className="p-6 text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
    </div>
  );
}
