"use client";

import { useEffect, useState } from "react";
import {
  type ReportFilters,
  type ReportAffiliateRow,
  type ReportResellerRow,
  getAffiliateBreakdown,
  getResellerBreakdown,
} from "@/lib/reports";
import { AffiliateBreakdownTable } from "../AffiliateBreakdownTable";
import { ResellerBreakdownTable } from "../ResellerBreakdownTable";

/**
 * ADR-086 PR-2 — the Reseller-wallet breakdown lands as a second panel in
 * this same tab (decision 2's build-time placement call), not a new
 * top-level tab: Affiliate (whitelabel brand) and Reseller (prepaid
 * wallet) are both "who bought at wholesale/via a channel" dimensions
 * over the same orders table, just mutually exclusive columns
 * (affiliate_id vs wallet_reseller_id) — grouping them here avoids an
 * unbounded tab count.
 */
export function AffiliatesTab({ token, filters }: { token: string; filters: ReportFilters }) {
  const [affiliateRows, setAffiliateRows] = useState<ReportAffiliateRow[] | null>(null);
  const [resellerRows, setResellerRows] = useState<ReportResellerRow[] | null>(null);

  useEffect(() => {
    getAffiliateBreakdown(token, filters)
      .then((res) => setAffiliateRows(res.affiliates))
      .catch(() => undefined);
    getResellerBreakdown(token, filters)
      .then((res) => setResellerRows(res.resellers))
      .catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, filters.year, filters.month, filters.affiliateId]);

  return (
    <div className="flex flex-col gap-6">
      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 className="px-4 pt-4 text-sm font-semibold text-gray-800 dark:text-white/90">Affiliates Breakdown</h2>
        {affiliateRows ? (
          <AffiliateBreakdownTable rows={affiliateRows} />
        ) : (
          <p className="p-6 text-sm text-gray-500 dark:text-gray-400">Loading…</p>
        )}
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 className="px-4 pt-4 text-sm font-semibold text-gray-800 dark:text-white/90">Resellers (Wallet) Breakdown</h2>
        {resellerRows ? (
          <ResellerBreakdownTable rows={resellerRows} />
        ) : (
          <p className="p-6 text-sm text-gray-500 dark:text-gray-400">Loading…</p>
        )}
      </div>
    </div>
  );
}
