"use client";

import { useEffect, useState } from "react";
import { type ReportFilters, type ReportAffiliateRow, getAffiliateBreakdown } from "@/lib/reports";
import { AffiliateBreakdownTable } from "../AffiliateBreakdownTable";

export function AffiliatesTab({ token, filters }: { token: string; filters: ReportFilters }) {
  const [rows, setRows] = useState<ReportAffiliateRow[] | null>(null);

  useEffect(() => {
    getAffiliateBreakdown(token, filters)
      .then((res) => setRows(res.affiliates))
      .catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, filters.year, filters.month, filters.affiliateId]);

  return (
    <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
      <h2 className="px-4 pt-4 text-sm font-semibold text-gray-800 dark:text-white/90">Affiliates Breakdown</h2>
      {rows ? <AffiliateBreakdownTable rows={rows} /> : <p className="p-6 text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
    </div>
  );
}
