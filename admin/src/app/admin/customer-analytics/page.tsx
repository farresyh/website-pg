"use client";

/**
 * ANL-1..4 (docs/prd.md §6.12). Mirrors
 * backend/app/Services/CustomerAnalytics/CustomerAnalyticsService.php's
 * pinned rules (grilled/pinned as ADR-049) — "customer" is a derived
 * `customer_email` grouping (no Customer model, ADR-011 untouched),
 * segment is always the customer's full lifetime standing, and the
 * year/month filter only narrows the displayed orders_count/total_spent
 * figures (and the CSV export), never the segment tag itself. This page
 * only renders what the backend returns.
 *
 * ADR-038: new screen, PrimeReact-Tailwind only (Button/Select/Tag/
 * DataTable), no old TailAdmin primitives.
 */

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectIndicator,
  SelectPortal,
  SelectPositioner,
  SelectPopup,
  SelectList,
  SelectOption,
} from "@/components/ui/select";
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
import { type ReportReseller, listReportResellers } from "@/lib/reports";
import {
  type CustomerAnalyticsFilters,
  type CustomerAnalyticsRow,
  type CustomerAnalyticsSummary,
  type CustomerSegment,
  getCustomerAnalyticsSummary,
  getCustomerAnalyticsCustomers,
  exportCustomerAnalytics,
} from "@/lib/customer-analytics";

const RESELLER_ALL = "all";
const YEAR_ALL = "all";
const SEGMENT_ALL = "all";

const currentYear = new Date().getFullYear();
const yearOptions = [YEAR_ALL, ...Array.from({ length: 5 }, (_, i) => String(currentYear - i))];
const monthOptions = [YEAR_ALL, ...Array.from({ length: 12 }, (_, i) => String(i + 1))];
const monthLabels: Record<string, string> = {
  all: "All months",
  "1": "January", "2": "February", "3": "March", "4": "April",
  "5": "May", "6": "June", "7": "July", "8": "August",
  "9": "September", "10": "October", "11": "November", "12": "December",
};

const segmentOptions: { label: string; value: string }[] = [
  { label: "All Segments", value: SEGMENT_ALL },
  { label: "VIP", value: "vip" },
  { label: "Frequent", value: "frequent" },
  { label: "Dormant", value: "dormant" },
  { label: "New", value: "new" },
  { label: "One-time", value: "one_time" },
];

const segmentSeverity: Record<CustomerSegment, "warn" | "info" | "secondary" | "success" | undefined> = {
  vip: "warn",
  frequent: "info",
  dormant: "secondary",
  new: "success",
  one_time: undefined,
};

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(value: string): string {
  return new Date(value).toLocaleDateString();
}

export default function CustomerAnalyticsPage() {
  const router = useRouter();
  const session = useClientSession();

  const [resellers, setResellers] = useState<ReportReseller[]>([]);
  const [resellerId, setResellerId] = useState<string>(RESELLER_ALL);
  const [year, setYear] = useState<string>(YEAR_ALL);
  const [month, setMonth] = useState<string>(YEAR_ALL);
  const [segment, setSegment] = useState<string>(SEGMENT_ALL);
  const [summary, setSummary] = useState<CustomerAnalyticsSummary | null>(null);
  const [rows, setRows] = useState<CustomerAnalyticsRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const filters: CustomerAnalyticsFilters = {
    year: year === YEAR_ALL ? undefined : Number(year),
    month: year === YEAR_ALL || month === YEAR_ALL ? undefined : Number(month),
    resellerId: resellerId === RESELLER_ALL ? undefined : Number(resellerId),
    segment: segment === SEGMENT_ALL ? undefined : (segment as CustomerSegment),
  };

  useEffect(() => {
    if (!session) return;
    listReportResellers(session.token).then(setResellers).catch(() => undefined);
  }, [session]);

  const refresh = useCallback((token: string, f: CustomerAnalyticsFilters) => {
    getCustomerAnalyticsSummary(token, f)
      .then(setSummary)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load stats."));
    getCustomerAnalyticsCustomers(token, f)
      .then((res) => setRows(res.customers))
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load customers."));
  }, []);

  useEffect(() => {
    if (!session) return;
    refresh(session.token, filters);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, year, month, resellerId, segment]);

  async function handleExport() {
    if (!session) return;
    setExporting(true);
    setError(null);
    try {
      await exportCustomerAnalytics(session.token, filters);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not export customers.");
    } finally {
      setExporting(false);
    }
  }

  const resellerOptions = [
    { label: "All Resellers", value: RESELLER_ALL },
    ...resellers.map((r) => ({ label: r.business_name, value: String(r.id) })),
  ];

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Customer Analytics</h1>
          <p className="mt-1 max-w-2xl text-sm text-gray-500 dark:text-gray-400">
            &quot;Customer&quot; is derived from <code>customer_email</code> — no accounts exist (guest checkout).
            A segment always reflects a customer&apos;s full order history; the filters below only narrow the
            orders/spend figures shown and the CSV export.
          </p>
        </div>
        <Button variant="outlined" size="small" disabled={exporting} onClick={handleExport}>
          {exporting ? "Exporting…" : "Export CSV"}
        </Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      {/* ANL-1: stat cards */}
      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Total Customers</p>
          <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{summary?.total_customers ?? "—"}</p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Avg Order Value</p>
          <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">
            {summary ? formatRm(summary.avg_order_value) : "—"}
          </p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Repeat Rate</p>
          <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">
            {summary ? `${summary.repeat_rate_pct}%` : "—"}
          </p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Top Spender</p>
          <p className="mt-1 truncate text-sm font-medium text-gray-800 dark:text-white/90">
            {summary?.top_spender ? summary.top_spender.customer_email : "—"}
          </p>
          {summary?.top_spender && (
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">{formatRm(summary.top_spender.total_spent)}</p>
          )}
        </div>
      </div>

      {/* ANL-4: filters */}
      <div className="mb-6 flex flex-wrap items-center gap-3">
        <FilterSelect label="Reseller" value={resellerId} onChange={setResellerId} options={resellerOptions} />
        <FilterSelect label="Segment" value={segment} onChange={setSegment} options={segmentOptions} />
        <FilterSelect
          label="Year"
          value={year}
          onChange={(v) => {
            setYear(v);
            if (v === YEAR_ALL) setMonth(YEAR_ALL);
          }}
          options={yearOptions.map((y) => ({ label: y === YEAR_ALL ? "All time" : y, value: y }))}
        />
        <FilterSelect
          label="Month"
          value={month}
          onChange={setMonth}
          disabled={year === YEAR_ALL}
          options={monthOptions.map((m) => ({ label: monthLabels[m], value: m }))}
        />
      </div>

      {/* ANL-3: customer table */}
      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={rows ?? []} dataKey="customer_email">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead>
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Email</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Name</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Segment</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Orders</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Total Spent</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Last Order</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody>
                  {({ item }) => {
                    const row = item as unknown as CustomerAnalyticsRow;

                    return (
                      <DataTableRow key={row.customer_email}>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                          {row.customer_email}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {row.customer_name ?? "—"}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          {row.segment ? <Tag severity={segmentSeverity[row.segment]}>{row.segment_label}</Tag> : "—"}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {row.orders_count}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {formatRm(row.total_spent)}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {formatDate(row.last_order_at)}
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>
          {rows?.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No customers found.</p>
          )}
          {rows === null && !error && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>
      </div>
    </div>
  );
}

function FilterSelect({
  label,
  value,
  onChange,
  options,
  disabled,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  options: { label: string; value: string }[];
  disabled?: boolean;
}) {
  return (
    <div className="flex items-center gap-2">
      <span className="text-theme-xs text-gray-500 dark:text-gray-400">{label}</span>
      <Select
        value={value}
        options={options}
        optionLabel="label"
        optionValue="value"
        disabled={disabled}
        onValueChange={(e) => onChange(e.value as string)}
      >
        <SelectTrigger className="min-w-[9rem]">
          <SelectValue />
          <SelectIndicator />
        </SelectTrigger>
        <SelectPortal>
          <SelectPositioner>
            <SelectPopup>
              <SelectList>
                {options.map((option, index) => (
                  <SelectOption key={option.value} index={index}>
                    {option.label}
                  </SelectOption>
                ))}
              </SelectList>
            </SelectPopup>
          </SelectPositioner>
        </SelectPortal>
      </Select>
    </div>
  );
}
