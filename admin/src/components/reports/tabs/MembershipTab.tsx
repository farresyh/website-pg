"use client";

import { useEffect, useState } from "react";
import { type ReportFilters, type ReportMembershipBreakdown, getMembershipBreakdown } from "@/lib/reports";
import { StatCard } from "../StatCard";
import { HorizontalBarList } from "../HorizontalBarList";
import { formatRm, toRm } from "../format";
import { TagIcon, TrendUpIcon, UserCircleIcon, ListIcon } from "@/icons";

export function MembershipTab({ token, filters }: { token: string; filters: ReportFilters }) {
  const [row, setRow] = useState<ReportMembershipBreakdown | null>(null);

  useEffect(() => {
    getMembershipBreakdown(token, filters)
      .then(setRow)
      .catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, filters.year, filters.month, filters.affiliateId]);

  return (
    <div>
      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard
          icon={<UserCircleIcon width={18} height={18} />}
          color="green"
          label="Member Sales"
          value={row ? formatRm(row.member_sales) : "—"}
          sub={row ? `${row.member_orders_count} member orders` : undefined}
        />
        <StatCard
          icon={<ListIcon width={18} height={18} />}
          color="blue"
          label="Standard Sales"
          value={row ? formatRm(row.standard_sales) : "—"}
          sub={row ? `${row.standard_orders_count} standard orders` : undefined}
        />
        <StatCard
          icon={<TrendUpIcon width={18} height={18} />}
          color="amber"
          label="Margin Forgone"
          value={row ? formatRm(row.margin_forgone) : "—"}
          sub="Discount given to members"
        />
        <StatCard
          icon={<TagIcon width={18} height={18} />}
          color="violet"
          label="Membership Fees"
          value={row ? formatRm(row.membership_fee_revenue) : "—"}
          sub="Subscription revenue (ledger)"
        />
      </div>

      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Sales by Pricing Basis</h2>
        {row ? (
          <HorizontalBarList
            items={[
              { label: "Standard (guest)", value: toRm(row.standard_sales), sublabel: `${row.standard_orders_count} orders` },
              { label: "Member", value: toRm(row.member_sales), sublabel: `${row.member_orders_count} orders` },
            ]}
            formatValue={(v) => `RM ${v.toLocaleString("en-MY", { maximumFractionDigits: 0 })}`}
          />
        ) : (
          <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
        )}
      </div>
    </div>
  );
}
