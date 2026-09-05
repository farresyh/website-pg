"use client";

/**
 * ANL-5 (docs/prd.md §6.12), grilled/pinned as ADR-050. Mirrors
 * backend/app/Services/CustomerAnalytics/CustomerAnalyticsService.php's
 * customerDetail() — this page only renders what the backend returns.
 *
 * The Profit Analysis panel is scoped to this customer's DELIVERED
 * orders only (ADR-050 decision 2) — a narrower, deliberately
 * different population than the stat cards above it (Paid-scoped,
 * same as the list screen's Total Spent). Two internally-consistent
 * scopes on one page, not a contradiction — see the backend service's
 * own doc comment for why.
 *
 * ADR-038: PrimeReact-Tailwind only (Button/Tag/DataTable), no old
 * TailAdmin primitives.
 */

import { useEffect, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import Link from "next/link";
import { Tag } from "@/components/ui/tag";
import {
  DataTable,
  DataTableTableContainer,
  DataTableTable,
  DataTableTHead,
  DataTableTHeadRow,
  DataTableTHeadCell,
  DataTableTBody,
  DataTableRow,
  DataTableCell,
} from "@/components/ui/datatable";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { type CustomerDetail, type CustomerSegment, getCustomerDetail } from "@/lib/customer-analytics";

const segmentSeverity: Record<CustomerSegment, "warn" | "info" | "secondary" | "success" | undefined> = {
  vip: "warn",
  frequent: "info",
  dormant: "secondary",
  new: "success",
  one_time: undefined,
};

const deliveryStatusSeverity: Record<string, "secondary" | "warn" | "success" | "danger" | "info"> = {
  not_started: "secondary",
  pending: "info",
  processing: "warn",
  delivered: "success",
  failed: "danger",
  needs_review: "warn",
};

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(value: string): string {
  return new Date(value).toLocaleDateString();
}

function pct(part: number, total: number): string {
  return total > 0 ? `${((part / total) * 100).toFixed(1)}%` : "—";
}

export default function CustomerDetailPage() {
  const router = useRouter();
  const params = useParams<{ email: string }>();
  const session = useClientSession();

  const [detail, setDetail] = useState<CustomerDetail | null>(null);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // params.email arrives as the raw URL segment (still percent-encoded,
  // e.g. "frequent%40example.com") — decode it before re-encoding in
  // getCustomerDetail(), or "@" round-trips through a double-encode
  // ("%2540") and the backend correctly 404s on a literal, wrong email.
  const email = decodeURIComponent(params.email);

  useEffect(() => {
    if (!session) return;
    getCustomerDetail(session.token, email)
      .then(setDetail)
      .catch((err: unknown) => {
        if (err instanceof ApiError && err.status === 404) {
          setNotFound(true);
          return;
        }
        setError(err instanceof ApiError ? err.message : "Could not load customer detail.");
      });
  }, [session, email]);

  if (notFound) {
    return (
      <div>
        <Link href="/admin/customer-analytics" className="text-theme-sm text-brand-500 hover:underline">
          ← Back to Customer Analytics
        </Link>
        <p className="mt-4 text-sm text-gray-500 dark:text-gray-400">No paid orders found for this customer.</p>
      </div>
    );
  }

  if (error) {
    return (
      <div>
        <Link href="/admin/customer-analytics" className="text-theme-sm text-brand-500 hover:underline">
          ← Back to Customer Analytics
        </Link>
        <p className="mt-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      </div>
    );
  }

  if (!detail) {
    return (
      <div>
        <Link href="/admin/customer-analytics" className="text-theme-sm text-brand-500 hover:underline">
          ← Back to Customer Analytics
        </Link>
        <p className="mt-4 text-sm text-gray-500 dark:text-gray-400">Loading…</p>
      </div>
    );
  }

  const revenue = detail.profit_analysis.total_revenue;
  const maxMonthlySpend = Math.max(1, ...detail.monthly_trend.map((m) => m.total_spent));

  return (
    <div>
      <Link href="/admin/customer-analytics" className="text-theme-sm text-brand-500 hover:underline">
        ← Back to Customer Analytics
      </Link>

      <div className="mt-2 mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Customer Details</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{detail.customer_name ?? detail.customer_email}</p>
        </div>
        {detail.segment && <Tag severity={segmentSeverity[detail.segment]}>{detail.segment_label}</Tag>}
      </div>

      {/* Identity cards */}
      <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Customer Name</p>
          <p className="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{detail.customer_name ?? "—"}</p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Phone</p>
          <p className="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{detail.customer_phone ?? "—"}</p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Email</p>
          <p className="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{detail.customer_email}</p>
        </div>
      </div>

      {/* Stat cards (Paid-scoped, same rule as the list screen) */}
      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Total Orders</p>
          <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{detail.stats.total_orders}</p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Total Spent</p>
          <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{formatRm(detail.stats.total_spent)}</p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Avg Order</p>
          <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{formatRm(detail.stats.avg_order_value)}</p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Customer Since</p>
          <p className="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{formatDate(detail.stats.customer_since)}</p>
        </div>
      </div>

      {/* Profit Analysis (delivered-orders-only, ADR-050 decision 2) */}
      <div className="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h3 className="mb-1 text-sm font-semibold text-gray-800 dark:text-white/90">Profit Analysis</h3>
        <p className="mb-4 text-theme-xs text-gray-500 dark:text-gray-400">
          Scoped to delivered orders only — a paid-but-undelivered order contributes to Total Spent above but $0
          recognized profit here until it delivers.
        </p>

        <div className="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div className="rounded-xl bg-blue-50 p-4 text-center dark:bg-blue-500/10">
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">Total Revenue</p>
            <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{formatRm(revenue)}</p>
          </div>
          <div className="rounded-xl bg-green-50 p-4 text-center dark:bg-green-500/10">
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">Affiliate Profit</p>
            <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">
              {formatRm(detail.profit_analysis.affiliate_commission)}
            </p>
          </div>
          <div className="rounded-xl bg-purple-50 p-4 text-center dark:bg-purple-500/10">
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">System Profit</p>
            <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">
              {formatRm(detail.profit_analysis.system_profit)}
            </p>
          </div>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left text-theme-sm">
            <thead>
              <tr className="border-b border-gray-200 text-theme-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                <th className="py-2 font-medium">Component</th>
                <th className="py-2 font-medium">Amount (RM)</th>
                <th className="py-2 font-medium">Percentage</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              <tr>
                <td className="py-2 text-gray-700 dark:text-gray-300">Total Revenue</td>
                <td className="py-2 text-gray-800 dark:text-white/90">{formatRm(revenue)}</td>
                <td className="py-2 text-gray-500 dark:text-gray-400">100%</td>
              </tr>
              <tr>
                <td className="py-2 text-gray-700 dark:text-gray-300">Supplier Cost</td>
                <td className="py-2 text-error-600 dark:text-error-400">-{formatRm(detail.profit_analysis.supplier_cost)}</td>
                <td className="py-2 text-gray-500 dark:text-gray-400">{pct(detail.profit_analysis.supplier_cost, revenue)}</td>
              </tr>
              <tr>
                <td className="py-2 text-gray-700 dark:text-gray-300">Affiliate Commission</td>
                <td className="py-2 text-success-600 dark:text-success-400">
                  -{formatRm(detail.profit_analysis.affiliate_commission)}
                </td>
                <td className="py-2 text-gray-500 dark:text-gray-400">
                  {pct(detail.profit_analysis.affiliate_commission, revenue)}
                </td>
              </tr>
              <tr>
                <td className="py-2 text-gray-700 dark:text-gray-300">Transaction Fees</td>
                <td className="py-2 text-error-600 dark:text-error-400">
                  -{formatRm(detail.profit_analysis.transaction_fees)}
                </td>
                <td className="py-2 text-gray-500 dark:text-gray-400">
                  {pct(detail.profit_analysis.transaction_fees, revenue)}
                </td>
              </tr>
              <tr className="font-semibold">
                <td className="py-2 text-gray-800 dark:text-white/90">Net System Profit</td>
                <td className="py-2 text-gray-800 dark:text-white/90">{formatRm(detail.profit_analysis.system_profit)}</td>
                <td className="py-2 text-gray-800 dark:text-white/90">{pct(detail.profit_analysis.system_profit, revenue)}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      {/* Monthly Spending Trend — plain CSS bars, no charting library (matches Dashboard's Hourly Activity precedent) */}
      <div className="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h3 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Monthly Spending Trend (Last 12 Months)</h3>
        {detail.monthly_trend.length === 0 ? (
          <p className="text-sm text-gray-500 dark:text-gray-400">No spend in the last 12 months.</p>
        ) : (
          <div className="flex items-end gap-3" style={{ height: "160px" }}>
            {detail.monthly_trend.map((m) => (
              <div key={m.month} className="flex flex-1 flex-col items-center gap-1">
                <span className="text-theme-xs text-gray-500 dark:text-gray-400">{formatRm(m.total_spent)}</span>
                <div
                  className="w-full rounded-t-md bg-brand-500/70"
                  style={{ height: `${Math.max(4, (m.total_spent / maxMonthlySpend) * 110)}px` }}
                />
                <span className="text-theme-xs text-gray-500 dark:text-gray-400">{m.label}</span>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Top Packages / Top Affiliates */}
      <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
          <h3 className="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">Top Packages</h3>
          {detail.top_packages.length === 0 ? (
            <p className="text-sm text-gray-500 dark:text-gray-400">No packages yet.</p>
          ) : (
            <div className="space-y-3">
              {detail.top_packages.map((row) => (
                <div key={row.id ?? row.name} className="flex items-center justify-between">
                  <div>
                    <p className="text-theme-sm font-medium text-gray-800 dark:text-white/90">{row.name}</p>
                    <p className="text-theme-xs text-gray-500 dark:text-gray-400">{row.orders_count} orders</p>
                  </div>
                  <div className="text-right">
                    <p className="text-theme-sm font-medium text-gray-800 dark:text-white/90">{formatRm(row.total_spent)}</p>
                    <p className="text-theme-xs text-gray-500 dark:text-gray-400">{row.pct_of_spend}%</p>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
          <h3 className="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">Top Affiliates</h3>
          {detail.top_affiliates.length === 0 ? (
            <p className="text-sm text-gray-500 dark:text-gray-400">No affiliates yet.</p>
          ) : (
            <div className="space-y-3">
              {detail.top_affiliates.map((row) => (
                <div key={row.id ?? row.name} className="flex items-center justify-between">
                  <div>
                    <p className="text-theme-sm font-medium text-gray-800 dark:text-white/90">{row.name}</p>
                    <p className="text-theme-xs text-gray-500 dark:text-gray-400">{row.orders_count} orders</p>
                  </div>
                  <div className="text-right">
                    <p className="text-theme-sm font-medium text-gray-800 dark:text-white/90">{formatRm(row.total_spent)}</p>
                    <p className="text-theme-xs text-gray-500 dark:text-gray-400">{row.pct_of_spend}%</p>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Order History — Paid-scoped; "—" for a not-yet-delivered order's profit columns (ADR-050 decision 5) */}
      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <h3 className="p-5 pb-0 text-sm font-semibold text-gray-800 dark:text-white/90">Order History</h3>
        <div className="max-w-full overflow-x-auto">
          <DataTable data={detail.order_history} dataKey="order_number">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead>
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Order</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Date</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Package</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Affiliate</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Revenue</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Affiliate Profit</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">System Profit</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody>
                  {({ item }) => {
                    const row = item as unknown as CustomerDetail["order_history"][number];

                    return (
                      <DataTableRow
                        key={row.order_number}
                        className="cursor-pointer hover:bg-gray-50 dark:hover:bg-white/[0.02]"
                        onClick={() => router.push(`/admin/orders?order=${row.id}`)}
                      >
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                          {row.order_number}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {formatDate(row.paid_at)}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {row.package_name}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {row.affiliate_name}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {formatRm(row.final_amount)}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {row.affiliate_profit === null ? "—" : formatRm(row.affiliate_profit)}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {row.system_profit === null ? "—" : formatRm(row.system_profit)}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Tag severity={deliveryStatusSeverity[row.delivery_status]}>{row.delivery_status}</Tag>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>
          {detail.order_history.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No orders found.</p>
          )}
        </div>
      </div>
    </div>
  );
}
