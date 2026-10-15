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
 * ADR-049 2026-10-15 addendum (item 103): the list is paged, sorted and
 * searched on the server; the date filter is the shared preset range
 * (lib/date-range.ts, as on Reports). A filter change aborts the request
 * it replaces, so an older answer never overwrites a newer one.
 *
 * ADR-038: new screen, PrimeReact-Tailwind only (Button/Select/Tag/
 * DataTable), no old TailAdmin primitives.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import { Input } from "@/components/ui/input";
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
import { useDebouncedValue } from "@/hooks/useDebouncedValue";
import { DATE_RANGE_PRESETS, type DateRangePreset, KL_TIMEZONE, resolveDateRange } from "@/lib/date-range";
import { ApiError } from "@/lib/api-client";
import { type ReportAffiliate, listReportAffiliates } from "@/lib/reports";
import { type ResellerRow, listResellers } from "@/lib/resellers";
import {
  type CustomerAnalyticsRow,
  type CustomerAnalyticsSummary,
  type CustomerListQuery,
  type CustomerSegment,
  type CustomerSort,
  type Paged,
  getCustomerAnalyticsSummary,
  getCustomerAnalyticsCustomers,
  exportCustomerAnalytics,
} from "@/lib/customer-analytics";

const AFFILIATE_ALL = "all";
const RESELLER_ALL = "all";
const SEGMENT_ALL = "all";

const sortOptions: { label: string; value: CustomerSort }[] = [
  { label: "Total spent", value: "total_spent" },
  { label: "Orders", value: "orders_count" },
  { label: "Last order", value: "last_order_at" },
];
const perPageOptions = [25, 50, 100].map((n) => ({ label: `${n} per page`, value: String(n) }));

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
  return new Date(value).toLocaleDateString("en-MY", { timeZone: KL_TIMEZONE });
}

function isAbort(err: unknown): boolean {
  return err instanceof DOMException && err.name === "AbortError";
}

export default function CustomerAnalyticsPage() {
  const router = useRouter();
  const session = useClientSession();

  const [affiliates, setAffiliates] = useState<ReportAffiliate[]>([]);
  const [affiliateId, setAffiliateId] = useState<string>(AFFILIATE_ALL);
  const [resellers, setResellers] = useState<ResellerRow[]>([]);
  const [resellerId, setResellerId] = useState<string>(RESELLER_ALL);
  const [rangePreset, setRangePreset] = useState<DateRangePreset>("all");
  const [customFrom, setCustomFrom] = useState("");
  const [customTo, setCustomTo] = useState("");
  const [segment, setSegment] = useState<string>(SEGMENT_ALL);
  const [searchInput, setSearchInput] = useState("");
  const [sort, setSort] = useState<CustomerSort>("total_spent");
  const [perPage, setPerPage] = useState(25);
  const [pageNumber, setPageNumber] = useState(1);
  // Each answer remembers the request it belongs to; anything else on screen is "loading".
  const [summaryResult, setSummaryResult] = useState<{ key: string; data: CustomerAnalyticsSummary } | null>(null);
  const [pageResult, setPageResult] = useState<{ key: string; data: Paged<CustomerAnalyticsRow> } | null>(null);
  const [loadError, setLoadError] = useState<{ key: string; message: string } | null>(null);
  const [exportError, setExportError] = useState<string | null>(null);
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const { from, to } = resolveDateRange(rangePreset, useDebouncedValue(customFrom), useDebouncedValue(customTo));
  const search = useDebouncedValue(searchInput.trim());
  const statFilters = {
    from,
    to,
    affiliateId: affiliateId === AFFILIATE_ALL ? undefined : Number(affiliateId),
    resellerId: resellerId === RESELLER_ALL ? undefined : Number(resellerId),
  };
  const listFilters: CustomerListQuery = {
    ...statFilters,
    segment: segment === SEGMENT_ALL ? undefined : (segment as CustomerSegment),
    // The API needs at least 2 characters; one is treated as no search.
    search: search.length >= 2 ? search : undefined,
    sort,
  };
  const statKey = JSON.stringify(statFilters);
  const requestKey = JSON.stringify({ ...listFilters, pageNumber, perPage });
  const summary = summaryResult?.key === statKey ? summaryResult.data : null;
  const page = pageResult?.key === requestKey ? pageResult.data : null;
  const error = exportError ?? (loadError && [statKey, requestKey].includes(loadError.key) ? loadError.message : null);

  /** A filter change always goes back to page 1. */
  function onFilter<T>(set: (value: T) => void) {
    return (value: T) => {
      set(value);
      setPageNumber(1);
    };
  }

  useEffect(() => {
    if (!session) return;
    listReportAffiliates().then(setAffiliates).catch(() => undefined);
    listResellers().then((res) => setResellers(res.resellers)).catch(() => undefined);
  }, [session]);

  useEffect(() => {
    if (!session) return;
    const controller = new AbortController();
    getCustomerAnalyticsSummary(statFilters, controller.signal)
      .then((data) => setSummaryResult({ key: statKey, data }))
      .catch((err: unknown) => {
        if (!isAbort(err)) setLoadError({ key: statKey, message: err instanceof ApiError ? err.message : "Could not load stats." });
      });
    return () => controller.abort();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, statKey]);

  useEffect(() => {
    if (!session) return;
    const controller = new AbortController();
    getCustomerAnalyticsCustomers({ ...listFilters, page: pageNumber, perPage }, controller.signal)
      .then((data) => setPageResult({ key: requestKey, data }))
      .catch((err: unknown) => {
        if (!isAbort(err)) setLoadError({ key: requestKey, message: err instanceof ApiError ? err.message : "Could not load customers." });
      });
    return () => controller.abort();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, requestKey]);

  async function handleExport() {
    if (!session) return;
    setExporting(true);
    setExportError(null);
    try {
      await exportCustomerAnalytics(listFilters);
    } catch (err) {
      setExportError(err instanceof ApiError ? err.message : "Could not export customers.");
    } finally {
      setExporting(false);
    }
  }

  const affiliateOptions = [
    { label: "All Affiliates", value: AFFILIATE_ALL },
    ...affiliates.map((r) => ({ label: r.business_name, value: String(r.id) })),
  ];

  // ADR-049 addendum — independent of the Affiliate filter above (a
  // wallet Reseller order's affiliate_id is always the primary brand,
  // so picking both at once naturally returns nothing rather than
  // needing a mutual-exclusion guard).
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
        <FilterSelect label="Date" value={rangePreset} onChange={onFilter((v: string) => setRangePreset(v as DateRangePreset))} options={DATE_RANGE_PRESETS} />
        {rangePreset === "custom" && (
          <div className="flex items-center gap-2">
            <Input type="date" aria-label="From" value={customFrom} onChange={(e) => onFilter(setCustomFrom)(e.target.value)} className="w-[9.5rem]" />
            <span className="text-theme-xs text-gray-500 dark:text-gray-400">to</span>
            <Input type="date" aria-label="To" value={customTo} onChange={(e) => onFilter(setCustomTo)(e.target.value)} className="w-[9.5rem]" />
          </div>
        )}
        <FilterSelect label="Affiliate" value={affiliateId} onChange={onFilter(setAffiliateId)} options={affiliateOptions} />
        <FilterSelect label="Reseller" value={resellerId} onChange={onFilter(setResellerId)} options={resellerOptions} />
        <FilterSelect label="Segment" value={segment} onChange={onFilter(setSegment)} options={segmentOptions} />
        <FilterSelect label="Sort" value={sort} onChange={onFilter((v: string) => setSort(v as CustomerSort))} options={sortOptions} />
        <Input
          type="search"
          aria-label="Search email"
          placeholder="Search email"
          value={searchInput}
          onChange={(e) => onFilter(setSearchInput)(e.target.value)}
          className="w-[14rem]"
        />
      </div>

      {/* ANL-3: customer table */}
      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={page?.data ?? []} dataKey="customer_email">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead>
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Email</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Name</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Source</DataTableTHeadCell>
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
                      <DataTableRow
                        key={row.customer_email}
                        className="cursor-pointer hover:bg-gray-50 dark:hover:bg-white/[0.02]"
                        onClick={() => router.push(`/admin/customer-analytics/${encodeURIComponent(row.customer_email)}`)}
                      >
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                          {row.customer_email}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {row.customer_name ?? "—"}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          {row.has_reseller_orders ? (
                            <Tag severity="info">{row.source_label}</Tag>
                          ) : (
                            <span className="text-gray-500 dark:text-gray-400">{row.source_label}</span>
                          )}
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
          {page?.data.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No customers found.</p>
          )}
          {page === null && !error && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>
      </div>

      {page && page.total > 0 && (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-gray-500 dark:text-gray-400">
          <span>
            Page {page.current_page} of {page.last_page} ({page.total} customers)
          </span>
          <div className="flex items-center gap-2">
            <FilterSelect label="Show" value={String(perPage)} onChange={onFilter((v: string) => setPerPage(Number(v)))} options={perPageOptions} />
            <Button size="small" variant="outlined" disabled={page.current_page <= 1} onClick={() => setPageNumber((p) => p - 1)}>
              Previous
            </Button>
            <Button size="small" variant="outlined" disabled={page.current_page >= page.last_page} onClick={() => setPageNumber((p) => p + 1)}>
              Next
            </Button>
          </div>
        </div>
      )}
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
